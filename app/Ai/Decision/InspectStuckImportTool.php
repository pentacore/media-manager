<?php

declare(strict_types=1);

namespace App\Ai\Decision;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Arr\ManualImportResolver;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Read-only inspection of a stuck Sonarr/Radarr import. Returns each candidate
 * file's mapping status, what it is, and the RAW upstream rejection reasons so
 * the DecisionAgent can reason over them and decide what to do (import via
 * ResolveManualImportTool, drop via RemoveStuckDownloadTool, or leave it).
 *
 * Intentionally NOT gated by the manual-import capability: looking is always
 * safe and lets the agent write a useful summary even when it can't act.
 */
class InspectStuckImportTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Inspect a stuck Sonarr/Radarr import (a "manual interaction required" download). Returns each candidate file, whether it maps to a series/movie, and the upstream rejection reasons verbatim. Call this FIRST for a ManualInteractionRequired event, read the rejections, then decide: import it (ResolveManualImportTool), remove it (RemoveStuckDownloadTool), or leave it for a human.';
    }

    public function handle(Request $request): Stringable|string
    {
        $args = $request->toArray();
        $service = mb_strtolower((string) ($args['service'] ?? ''));
        $downloadId = (string) ($args['download_id'] ?? '');

        $type = match ($service) {
            'sonarr' => ServiceType::Sonarr,
            'radarr' => ServiceType::Radarr,
            default => null,
        };
        if ($type === null) {
            return $this->encode(['ok' => false, 'reason' => 'invalid_service', 'message' => 'service must be "sonarr" or "radarr".']);
        }

        if ($downloadId === '') {
            return $this->encode(['ok' => false, 'reason' => 'missing_download_id', 'message' => 'download_id is required (from the event payload).']);
        }

        try {
            $connection = ServiceConnection::resolveActive($type);
            $client = $type === ServiceType::Sonarr
                ? new SonarrClient($connection)
                : new RadarrClient($connection);
            $candidates = $client->getManualImport(['downloadId' => $downloadId]);
        } catch (Throwable $throwable) {
            Log::warning('InspectStuckImportTool: lookup failed', [
                'service' => $service,
                'download_id' => $downloadId,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return $this->encode(['ok' => false, 'reason' => 'lookup_failed', 'message' => 'Could not enumerate import candidates.']);
        }

        $resolver = resolve(ManualImportResolver::class);
        $assessment = $resolver->assess($candidates, $service, $downloadId);

        return $this->encode([
            'ok' => true,
            'service' => $service,
            'download_id' => $downloadId,
            'total' => $assessment['total'],
            'importable' => $assessment['importable'],
            'fully_mapped' => $assessment['fully_mapped'],
            'files' => $resolver->describe($candidates, $service),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => $schema->string()
                ->description('The arr service the stuck download belongs to: "sonarr" or "radarr".')
                ->required(),
            'download_id' => $schema->string()
                ->description('The downloadId from the ManualInteractionRequired event payload.')
                ->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? '{"ok":false,"reason":"encoding_failed"}' : $encoded;
    }
}
