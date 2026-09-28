<?php

declare(strict_types=1);

namespace App\Ai\Tools\Decision;

use App\Ai\Decision\DecisionRunContext;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Arr\ManualImportResolver;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
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
 * safe and lets the agent write a useful summary even when it can't act. Also
 * works without a decision run bound, since the chat sub-agent calls it too.
 */
class InspectStuckImportTool extends DecisionTool
{
    protected const string OUTCOME_KEY = 'ok';

    public function description(): Stringable|string
    {
        return 'Inspect a stuck Sonarr/Radarr import (a "manual interaction required" download). Returns each candidate file, whether it maps to a series/movie, and the upstream rejection reasons verbatim. Call this FIRST for a ManualInteractionRequired event, read the rejections, then decide: import it (ResolveManualImportTool), remove it (RemoveStuckDownloadTool), or leave it for a human.';
    }

    protected function requiresRunContext(): bool
    {
        return false;
    }

    protected function countsTowardActionCap(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(Request $request): array
    {
        $validated = $request->validate([
            'service' => ['required', 'string', 'regex:/^(sonarr|radarr)$/Di'],
            'download_id' => ['required', 'string'],
        ], [
            'service.required' => 'service must be "sonarr" or "radarr".',
            'service.regex' => 'service must be "sonarr" or "radarr".',
            'download_id.required' => 'download_id is required (from the event payload).',
        ]);
        $service = mb_strtolower((string) $validated['service']);
        $downloadId = (string) $validated['download_id'];
        $type = $service === 'sonarr' ? ServiceType::Sonarr : ServiceType::Radarr;

        try {
            $context = $this->runContext();
            $connection = $context instanceof DecisionRunContext
                ? $context->resolveConnection($type)
                : ServiceConnection::resolveActive($type);
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

            return ['ok' => false, 'reason' => 'lookup_failed', 'message' => 'Could not enumerate import candidates.'];
        }

        $manualImportResolver = resolve(ManualImportResolver::class);
        $assessment = $manualImportResolver->assess($candidates, $service, $downloadId);

        return [
            'ok' => true,
            'service' => $service,
            'download_id' => $downloadId,
            'total' => $assessment['total'],
            'importable' => $assessment['importable'],
            'fully_mapped' => $assessment['fully_mapped'],
            'files' => $manualImportResolver->describe($candidates, $service),
        ];
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
}
