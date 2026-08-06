<?php

declare(strict_types=1);

namespace App\Ai\Tools\Arr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\MediaReplacement\MediaFileInspector;
use App\Services\MediaReplacement\ReplacementCandidateFinder;
use App\Services\MediaReplacement\ReplacementRequestBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Stringable;

class ReplaceMediaFileTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Queue a subtitle replacement for an installed Sonarr episode or Radarr movie. This is destructive: the reviewed '
            .'file is deleted only AFTER a replacement grab is accepted, behind the existing approval pipeline. Re-inspects the target '
            .'and re-runs the ranked search, then matches the candidate_fingerprint you selected. Use selection_mode=automatic only with '
            .'the fingerprint the finder returned as automatic_candidate; otherwise use selection_mode=manual with a fingerprint the user '
            .'chose. State every affected episode/file before calling — season packs replace multiple files.';
    }

    public function risk(): Risk
    {
        return Risk::Destructive;
    }

    /**
     * @return array{type: string, source_service: string, target_service: string, force_requires_approval: bool, payload: array<string, mixed>}
     */
    protected function execute(Request $request): array
    {
        $args = $request->toArray();
        $service = mb_strtolower(trim((string) ($args['service'] ?? '')));
        $fingerprint = (string) ($args['candidate_fingerprint'] ?? '');
        $selectionMode = ($args['selection_mode'] ?? null) === 'automatic' ? 'automatic' : 'manual';
        $reason = (string) ($args['reason'] ?? '');
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

        throw_if(
            ($snapshot['ambiguous'] ?? false) === true,
            InvalidArgumentException::class,
            'The target is ambiguous. Inspect and clarify with the user before replacing.',
        );

        $result = resolve(ReplacementCandidateFinder::class)->find(
            target: $snapshot,
            languageOverride: $this->languageOverride($args['required_languages'] ?? null),
            limit: 10,
            serviceConnection: $serviceConnection,
        );

        $candidate = $this->matchCandidate($result['candidates'], $fingerprint);

        throw_if(
            $candidate === null,
            InvalidArgumentException::class,
            'The selected candidate is no longer eligible. Re-run the search and choose again.',
        );

        if ($selectionMode === 'automatic') {
            $automatic = $result['automatic_candidate'];

            throw_if(
                ! is_array($automatic) || ($automatic['fingerprint'] ?? null) !== $fingerprint,
                InvalidArgumentException::class,
                "Automatic selection is only allowed for the finder's automatic_candidate.",
            );
        }

        $built = resolve(ReplacementRequestBuilder::class)->build(
            snapshot: $snapshot,
            candidate: $candidate,
            requiredLanguages: $result['effective_languages'],
            selectionMode: $selectionMode,
            reason: $reason,
        );

        return [
            'type' => 'replace_media_file',
            'source_service' => 'ai',
            'target_service' => $service,
            'force_requires_approval' => $built['force_requires_approval'],
            'payload' => $built['payload'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    private function matchCandidate(array $candidates, string $fingerprint): ?array
    {
        if ($fingerprint === '') {
            return null;
        }

        foreach ($candidates as $candidate) {
            if (($candidate['fingerprint'] ?? null) === $fingerprint) {
                return $candidate;
            }
        }

        return null;
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
                ->description('Sonarr series id or Radarr movie id.')
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
            'candidate_fingerprint' => $schema->string()
                ->description('The fingerprint of the candidate to grab, taken from FindReplacementCandidatesTool output.')
                ->required(),
            'selection_mode' => $schema->string()
                ->enum(['manual', 'automatic'])
                ->description("manual when the user chose the candidate; automatic only for the finder's automatic_candidate.")
                ->required(),
            'required_languages' => $schema->array()
                ->items($schema->string())
                ->description('The required subtitle languages for this request, or null to use configured defaults. Must match the search.')
                ->required()
                ->nullable(),
            'reason' => $schema->string()
                ->description('Short human-readable rationale shown on the approval card (e.g. "Current file has no English subtitles").')
                ->required(),
        ];
    }
}
