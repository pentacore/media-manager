<?php

declare(strict_types=1);

namespace App\Ai\Tools\Decision;

use App\Ai\Decision\StuckImportDecider;
use App\Ai\Decision\StuckImportDecision;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Chat's fast first look at a stuck download: the deterministic inspection
 * plus the classifier's import/remove/manual verdict, without the
 * investigator sub-agent's LLM loop. Read-only; MediaAgent only has it while
 * the stuck-import fast path is enabled.
 */
class StuckImportVerdictTool extends DecisionTool
{
    protected const string OUTCOME_KEY = 'ok';

    public function description(): Stringable|string
    {
        return 'Fast verdict on a stuck Sonarr/Radarr download when you already know its download_id: inspects the candidate files and returns a classifier recommendation (import, remove or manual) with its confidence. Read-only. If confident is false, or you do not know the download_id, use InvestigateStuckDownload instead.';
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
            'service.string' => 'service must be "sonarr" or "radarr".',
            'service.regex' => 'service must be "sonarr" or "radarr".',
            'download_id.required' => 'download_id is required.',
            'download_id.string' => 'download_id must be a string.',
        ]);
        $service = mb_strtolower((string) $validated['service']);
        $downloadId = (string) $validated['download_id'];

        try {
            $connection = ServiceConnection::resolveActive($service === 'sonarr' ? ServiceType::Sonarr : ServiceType::Radarr);
        } catch (Throwable $throwable) {
            Log::warning('StuckImportVerdictTool: connection lookup failed', [
                'service' => $service,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return ['ok' => false, 'reason' => 'lookup_failed', 'message' => 'No active connection for that service.'];
        }

        $stuckImportDecision = resolve(StuckImportDecider::class)->decide($connection, $service, $downloadId);

        if (! $stuckImportDecision instanceof StuckImportDecision) {
            return ['ok' => false, 'reason' => 'no_verdict', 'message' => 'No classifier verdict is available. Use InvestigateStuckDownload.'];
        }

        return [
            'ok' => true,
            'confident' => $stuckImportDecision->isConfident,
            'recommendation' => $stuckImportDecision->choice->value,
            'probability' => $stuckImportDecision->probability,
            'blocklist' => $stuckImportDecision->blocklist,
            'search_replacement' => $stuckImportDecision->searchReplacement,
            ...$stuckImportDecision->inspection,
            'message' => $stuckImportDecision->isConfident
                ? 'Confident verdict. Tell the user, and use the manual-import or remove tools only if they agree.'
                : 'Not confident. Use InvestigateStuckDownload for a full investigation.',
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => $schema->string()->description('The arr service the stuck download belongs to: "sonarr" or "radarr".')->required(),
            'download_id' => $schema->string()->description("The stuck download's downloadId.")->required(),
        ];
    }
}
