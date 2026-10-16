<?php

declare(strict_types=1);

namespace App\Ai\Tools\Decision;

use App\Ai\Decision\DecisionRunContext;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Arr\StuckImportInspector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Read-only inspection of a stuck Sonarr/Radarr import. Returns each candidate
 * file's mapping status, what it is, and the RAW upstream rejection reasons.
 * Two callers use it — the DecisionAgent (acting through ResolveManualImportTool
 * / RemoveStuckDownloadTool) and the chat StuckDownloadInvestigatorAgent (whose
 * parent acts through the *ChatTool variants) — so its description names no
 * acting tool.
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
        return 'Inspect one or more stuck Sonarr/Radarr imports ("manual interaction required" downloads). Read-only. Accepts ONE OR MORE download ids: pass all stuck downloads of a service in a single call. Returns, per download, each candidate file, whether it maps to a series/movie, and the upstream rejection reasons verbatim. Call this first for a stuck download and read the rejections before deciding whether it should be imported, removed, or left for a human.';
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
            'download_ids' => ['required', 'array', 'min:1', 'max:20'],
            'download_ids.*' => ['required', 'string', 'distinct'],
        ], [
            'service.required' => 'service must be "sonarr" or "radarr".',
            'service.string' => 'service must be "sonarr" or "radarr".',
            'service.regex' => 'service must be "sonarr" or "radarr".',
            'download_ids.required' => 'download_ids needs at least one downloadId (from the event payload).',
            'download_ids.array' => 'download_ids must be a list of downloadIds (from the event payload).',
            'download_ids.min' => 'download_ids needs at least one downloadId (from the event payload).',
            'download_ids.max' => 'download_ids takes at most 20 downloadIds per call.',
            'download_ids.*.required' => 'every download id must be a string (from the event payload).',
            'download_ids.*.string' => 'every download id must be a string (from the event payload).',
            'download_ids.*.distinct' => 'download_ids must not repeat an id.',
        ]);
        $service = mb_strtolower((string) $validated['service']);
        /** @var list<string> $downloadIds */
        $downloadIds = array_values(array_map(strval(...), $validated['download_ids']));
        $type = $service === 'sonarr' ? ServiceType::Sonarr : ServiceType::Radarr;

        try {
            $context = $this->runContext();
            $connection = $context instanceof DecisionRunContext
                ? $context->resolveConnection($type)
                : ServiceConnection::resolveActive($type);
            $stuckImportInspector = resolve(StuckImportInspector::class);
            // One id keeps buildClient()'s retry (a throw is this tool's lookup_failed);
            // the pooled read for several makes one attempt per id.
            $inspections = count($downloadIds) === 1
                ? [['ok' => true, ...$stuckImportInspector->inspect($connection, $service, $downloadIds[0])]]
                : $stuckImportInspector->inspectMany($connection, $service, $downloadIds);
        } catch (Throwable $throwable) {
            Log::warning('InspectStuckImportTool: lookup failed', [
                'service' => $service,
                'download_ids' => $downloadIds,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return ['ok' => false, 'reason' => 'lookup_failed', 'message' => 'Could not enumerate import candidates.'];
        }

        return ['ok' => true, 'service' => $service, 'downloads' => $inspections];
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
            'download_ids' => $schema->array()
                ->items($schema->string())
                ->description('Every downloadId to inspect (1-20). Pass all stuck downloads of this service in ONE call.')
                ->required(),
        ];
    }
}
