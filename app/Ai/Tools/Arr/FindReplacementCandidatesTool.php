<?php

declare(strict_types=1);

namespace App\Ai\Tools\Arr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\MediaReplacement\MediaFileInspector;
use App\Services\MediaReplacement\ReplacementCandidateFinder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Stringable;

class FindReplacementCandidatesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Find ranked subtitle-replacement candidates for an installed Sonarr episode or Radarr movie. It first inspects the '
            .'target; if the inspection is ambiguous it returns that ambiguity and you must ask the user to clarify instead of searching. '
            .'Otherwise it runs the native interactive release search and returns a compact ranked shortlist with subtitle confidence and '
            .'matched-rule evidence. Pass required_languages to override the configured languages for this request only, or null to use the '
            .'defaults. If automatic_candidate is null, you MUST present the candidates and let the user choose — do not pick one yourself.';
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
        $service = mb_strtolower(trim((string) ($args['service'] ?? '')));
        $serviceConnection = $this->serviceConnection(
            $service,
            $this->nullableInt($args['service_connection_id'] ?? null),
        );

        $snapshot = resolve(MediaFileInspector::class)->inspect(
            service: $service,
            itemId: (int) ($args['item_id'] ?? 0),
            seasonNumber: $this->nullableInt($args['season_number'] ?? null),
            episodeNumber: $this->nullableInt($args['episode_number'] ?? null),
            absoluteEpisodeNumber: $this->nullableInt($args['absolute_episode_number'] ?? null),
            serviceConnection: $serviceConnection,
        );

        if (($snapshot['ambiguous'] ?? false) === true) {
            return $snapshot;
        }

        return resolve(ReplacementCandidateFinder::class)->find(
            target: $snapshot,
            languageOverride: $this->languageOverride($args['required_languages'] ?? null),
            limit: $this->nullableInt($args['limit'] ?? null) ?? 5,
            serviceConnection: $serviceConnection,
        );
    }

    /**
     * @return array<int, string>|null
     */
    private function languageOverride(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return array_values(array_filter($value, is_string(...)));
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function serviceConnection(string $service, ?int $serviceConnectionId): ServiceConnection
    {
        $serviceType = match ($service) {
            'sonarr' => ServiceType::Sonarr,
            'radarr' => ServiceType::Radarr,
            default => throw new InvalidArgumentException('service must be "sonarr" or "radarr".'),
        };
        $builder = ServiceConnection::query()
            ->where('type', $serviceType)
            ->where('is_active', true);

        if ($serviceConnectionId !== null) {
            return $builder->whereKey($serviceConnectionId)->firstOrFail();
        }

        $connections = $builder->limit(2)->get();
        throw_unless(
            $connections->count() === 1,
            InvalidArgumentException::class,
            'Specify service_connection_id because the active service connection is ambiguous.',
        );

        return $connections->firstOrFail();
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
            'service_connection_id' => $schema->integer()
                ->description('Exact active Sonarr or Radarr connection ID. Required when more than one matching connection is active.')
                ->required()
                ->nullable(),
            'item_id' => $schema->integer()
                ->description('Sonarr series id or Radarr movie id. Never guess it.')
                ->required(),
            'season_number' => $schema->integer()
                ->description('Sonarr only: season number. Pass null for Radarr.')
                ->required()
                ->nullable(),
            'episode_number' => $schema->integer()
                ->description('Sonarr only: episode number. Pass null for Radarr.')
                ->required()
                ->nullable(),
            'absolute_episode_number' => $schema->integer()
                ->description('Sonarr anime only: absolute episode number. Pass null otherwise.')
                ->required()
                ->nullable(),
            'required_languages' => $schema->array()
                ->items($schema->string())
                ->description('Override the configured subtitle languages for this request only (e.g. ["English","Swedish"]). Pass null to use the configured defaults.')
                ->required()
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Max candidates to return (1-10, default 5). Pass null for the default.')
                ->required()
                ->nullable(),
        ];
    }
}
