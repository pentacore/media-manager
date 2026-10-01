<?php

declare(strict_types=1);

namespace App\Ai\Tools\Arr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\MediaSearchCommand;
use App\Models\ServiceConnection;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchMediaReleasesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Start an automatic indexer search in Sonarr or Radarr ("Search now"): for one series, season, '
            .'episodes or movies, or library-wide for every missing monitored item or every item below its quality '
            .'cutoff. Use the ids from SearchMediaTool/GetMediaTool. Queues an ActionRequest; the service grabs the '
            .'best release it finds.';
    }

    public function risk(): Risk
    {
        return Risk::Destructive;
    }

    /**
     * @return array{type: string, target_service: string, payload: array<string, mixed>, fallback_title: ?string}
     */
    protected function execute(Request $request): array
    {
        $validated = $request->validate([
            'service' => ['required', 'string', Rule::in(['sonarr', 'radarr'])],
            'command' => ['required', 'string', MediaSearchCommand::validationRule(), function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                if (MediaSearchCommand::tryFrom((string) $value)?->service()->value !== $request->string('service')->toString()) {
                    $fail('That search does not belong to this service.');
                }
            }],
            'series_id' => ['nullable', 'integer', 'min:1', 'required_if:command,series_search,season_search,episode_search'],
            'season_number' => ['nullable', 'integer', 'min:0', 'required_if:command,season_search'],
            'episode_ids' => ['nullable', 'array', 'max:500', 'required_if:command,episode_search'],
            'episode_ids.*' => ['integer', 'min:1'],
            'movie_ids' => ['nullable', 'array', 'max:500', 'required_if:command,movies_search'],
            'movie_ids.*' => ['integer', 'min:1'],
        ]);

        $command = MediaSearchCommand::from($validated['command']);
        $serviceType = $command->service();
        $payload = ['service' => $serviceType->value, 'command' => $command->value];

        foreach (['series_id', 'season_number'] as $key) {
            if (isset($validated[$key])) {
                $payload[$key] = (int) $validated[$key];
            }
        }

        foreach (['episode_ids', 'movie_ids'] as $key) {
            if (! empty($validated[$key])) {
                $payload[$key] = array_values(array_map(intval(...), $validated[$key]));
            }
        }

        return [
            'type' => 'search_media',
            'target_service' => $serviceType->value,
            'payload' => [...$payload, 'service_connection_id' => ServiceConnection::resolveActive($serviceType)->id],
            'fallback_title' => is_string($request['title'] ?? null) ? $request['title'] : null,
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => $schema->string()
                ->enum(['sonarr', 'radarr'])
                ->description('sonarr for TV series, radarr for movies.')
                ->required(),
            'command' => $schema->string()
                ->enum(MediaSearchCommand::values())
                ->description('sonarr: series_search, season_search, episode_search, missing_episode_search (all missing monitored episodes), cutoff_unmet_episode_search. '
                    .'radarr: movies_search, missing_movies_search (all missing monitored movies), cutoff_unmet_movies_search.')
                ->required(),
            'series_id' => $schema->integer()
                ->description('Sonarr series id. Required for series_search, season_search and episode_search; null otherwise.')
                ->required()
                ->nullable(),
            'season_number' => $schema->integer()
                ->description('Season number (0 = specials). Required for season_search; null otherwise.')
                ->required()
                ->nullable(),
            'episode_ids' => $schema->array()
                ->items($schema->integer())
                ->description('Sonarr episode ids. Required for episode_search; null otherwise.')
                ->required()
                ->nullable(),
            'movie_ids' => $schema->array()
                ->items($schema->integer())
                ->description('Radarr movie ids. Required for movies_search; null otherwise.')
                ->required()
                ->nullable(),
            'title' => $schema->string()
                ->description('Human name of the item searched for, as shown to the user. Only displayed if the server cannot look the id up. Null for library-wide searches.')
                ->required()
                ->nullable(),
        ];
    }
}
