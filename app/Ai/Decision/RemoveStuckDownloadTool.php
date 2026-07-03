<?php

declare(strict_types=1);

namespace App\Ai\Decision;

use App\Services\Actions\ActionOrchestrator;
use App\Settings\DecisionAgentSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Removes a stuck Sonarr/Radarr download from the queue WITHOUT blocklisting —
 * the resolution for a stuck import the agent decides shouldn't be imported
 * (e.g. "not an upgrade for existing episode file(s)").
 *
 * Gated behind the same manual-import capability as importing. Suggest-vs-act
 * is governed by the remove_stuck_download action rule (approval-required by
 * default, since this deletes the downloaded data).
 */
class RemoveStuckDownloadTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Remove a stuck Sonarr/Radarr download from the queue (without blocklisting) — use when an inspected stuck import should NOT be imported, e.g. it is "not an upgrade for existing episode file(s)". Provide the service, the download_id, and a short reason. This deletes the downloaded data and defaults to requiring human approval.';
    }

    public function handle(Request $request): Stringable|string
    {
        $context = app()->bound(DecisionRunContext::class) ? resolve(DecisionRunContext::class) : null;
        if (! $context instanceof DecisionRunContext) {
            return $this->encode(['queued' => false, 'reason' => 'no_active_run']);
        }

        if (! resolve(DecisionAgentSettings::class)->allowManualImport()) {
            return $this->encode([
                'queued' => false,
                'reason' => 'capability_disabled',
                'message' => 'Manual-import resolution is disabled in Decision Agent settings; you cannot remove stuck downloads. Note this in your summary.',
            ]);
        }

        if ($context->capReached()) {
            return $this->encode(['queued' => false, 'reason' => 'max_actions_reached']);
        }

        $args = $request->toArray();
        $service = mb_strtolower((string) ($args['service'] ?? ''));
        $downloadId = (string) ($args['download_id'] ?? '');
        $reason = (string) ($args['reason'] ?? '');

        if (! in_array($service, ['sonarr', 'radarr'], true)) {
            return $this->encode(['queued' => false, 'reason' => 'invalid_service', 'message' => 'service must be "sonarr" or "radarr".']);
        }

        if ($downloadId === '') {
            return $this->encode(['queued' => false, 'reason' => 'missing_download_id', 'message' => 'download_id is required.']);
        }

        if ($reason === '') {
            return $this->encode(['queued' => false, 'reason' => 'missing_reason', 'message' => 'A short reason is required so the human approver understands why.']);
        }

        try {
            $actionRequest = resolve(ActionOrchestrator::class)->dispatchFromAgent(
                type: 'remove_stuck_download',
                sourceService: $service,
                targetService: $service,
                payload: ['service' => $service, 'download_id' => $downloadId],
                rationale: Str::limit(sprintf('Remove stuck %s download %s: %s', $service, $downloadId, $reason), 1000, ''),
                webhookEventId: $context->webhookEventId,
            );
        } catch (Throwable $throwable) {
            Log::warning('RemoveStuckDownloadTool: dispatch failed', [
                'service' => $service,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return $this->encode(['queued' => false, 'reason' => 'dispatch_failed']);
        }

        if ($actionRequest === null) {
            return $this->encode([
                'queued' => false,
                'reason' => 'no_action_type_config',
                'message' => 'The remove_stuck_download Action Rule is missing or disabled; an admin must enable it.',
            ]);
        }

        $context->recordQueued($actionRequest->id, $actionRequest->requires_approval);

        return $this->encode([
            'queued' => true,
            'action_request_id' => $actionRequest->id,
            'status' => $actionRequest->status->value,
            'requires_approval' => $actionRequest->requires_approval,
            'remaining_budget' => $context->remainingBudget(),
            'message' => $actionRequest->requires_approval
                ? 'Removal queued for human approval.'
                : 'Removal queued and will auto-run.',
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
            'reason' => $schema->string()
                ->description('Short plain-English reason for removing rather than importing (e.g. "not an upgrade for existing file").')
                ->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? '{"queued":false,"reason":"encoding_failed"}' : $encoded;
    }
}
