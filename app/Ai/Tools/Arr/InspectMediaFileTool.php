<?php

declare(strict_types=1);

namespace App\Ai\Tools\Arr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Services\MediaReplacement\MediaFileInspector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class InspectMediaFileTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Inspect the installed Sonarr episode file or Radarr movie file whose subtitles may be wrong, BEFORE searching for a '
            .'replacement. Returns the current file ids, scene name, quality, and normalized subtitle languages with the resolved '
            .'anime/tv/movie scope. Never guess a series, episode, movie, or file id — resolve it first, then call this tool. If the '
            .'result has ambiguous=true, present the choices/affected episodes and ask the user which target they mean.';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(Request $request): array
    {
        $args = $request->toArray();

        return resolve(MediaFileInspector::class)->inspect(
            service: (string) ($args['service'] ?? ''),
            itemId: (int) ($args['item_id'] ?? 0),
            seasonNumber: $this->nullableInt($args['season_number'] ?? null),
            episodeNumber: $this->nullableInt($args['episode_number'] ?? null),
            absoluteEpisodeNumber: $this->nullableInt($args['absolute_episode_number'] ?? null),
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => $schema->string()
                ->enum(['sonarr', 'radarr'])
                ->description('sonarr for a TV/anime episode, radarr for a movie.')
                ->required(),
            'item_id' => $schema->integer()
                ->description('Sonarr series id or Radarr movie id. Never guess it.')
                ->required(),
            'season_number' => $schema->integer()
                ->description('Sonarr only: season number of the episode. Pass null for Radarr.')
                ->required()
                ->nullable(),
            'episode_number' => $schema->integer()
                ->description('Sonarr only: episode number within the season. Pass null for Radarr or to select a whole season (which is ambiguous).')
                ->required()
                ->nullable(),
            'absolute_episode_number' => $schema->integer()
                ->description('Sonarr anime only: absolute episode number, when the user refers to it that way. Pass null otherwise.')
                ->required()
                ->nullable(),
        ];
    }
}
