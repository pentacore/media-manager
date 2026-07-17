<?php

declare(strict_types=1);

namespace App\Ai\Decision;

use App\Enums\MediaReplacementStatus;
use App\Models\MediaReplacementAttempt;
use App\Models\WebhookEvent;
use App\Services\Actions\ActionOrchestrator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * The DecisionAgent's only mutating tool. It hands a proposed action to the
 * ActionOrchestrator's agent path, which decides suggest-vs-act from the
 * per-type ActionTypeConfig.requires_approval flag (NOT the chat AiMode).
 *
 * Implemented against the raw Tool contract rather than BaseTool so it
 * bypasses BaseTool's chat-advisory gate and auth()-bound action queueing.
 */
class ProposeActionTool implements Tool
{
    /**
     * Action types the DecisionAgent is permitted to propose. A safety net so
     * a hallucinated type can never reach the orchestrator. Must stay a subset
     * of the types ExecuteActionRequest can resolve an executor for. Stage 2
     * adds 'resolve_manual_import'.
     */
    public const array ALLOWED_TYPES = [
        'add_series',
        'monitor_series',
        'set_series_quality_profile',
        'delete_series',
        'add_movie',
        'monitor_movie',
        'set_movie_quality_profile',
        'delete_movie',
        'approve_seerr_request',
        'decline_seerr_request',
        'cleanup_seerr_request',
        'emby_library_scan',
    ];

    /**
     * Types that must ALWAYS land as Pending on the agent path, regardless of
     * ActionTypeConfig.requires_approval. The DecisionAgent's prompt embeds
     * third-party-authored webhook text (request titles/notes, release
     * names), so a crafted string could steer it into approving/denying
     * Seerr requests — an approval bypass if these auto-executed. Forcing a
     * human approval keeps prompt injection from turning into real
     * downloads or dropped requests.
     */
    public const array FORCED_APPROVAL_TYPES = [
        'approve_seerr_request',
        'decline_seerr_request',
        'cleanup_seerr_request',
    ];

    public function description(): Stringable|string
    {
        return 'Propose ONE concrete action in response to the inbound event. Suggest-vs-act is decided by admin rules, not by you. Call this once per distinct action you want taken (subject to a per-run cap). If no action is warranted, do NOT call this — just explain your reasoning in your final reply. Never guess IDs; rely on the event payload and read tools.';
    }

    public function handle(Request $request): string
    {
        $context = app()->bound(DecisionRunContext::class)
            ? resolve(DecisionRunContext::class)
            : null;

        if (! $context instanceof DecisionRunContext) {
            return $this->encode([
                'queued' => false,
                'reason' => 'no_active_run',
                'message' => 'No active decision run; cannot propose actions.',
            ]);
        }

        if ($context->capReached()) {
            return $this->encode([
                'queued' => false,
                'reason' => 'max_actions_reached',
                'message' => 'The per-run action cap has been reached. Do not propose further actions; summarize what you have queued.',
            ]);
        }

        $args = $request->toArray();
        $type = (string) ($args['type'] ?? '');
        $targetService = (string) ($args['target_service'] ?? '');
        $rationale = (string) ($args['rationale'] ?? '');
        $payload = is_array($args['payload'] ?? null) ? $args['payload'] : [];

        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            return $this->encode([
                'queued' => false,
                'reason' => 'type_not_allowed',
                'message' => sprintf('"%s" is not a proposable action type. Allowed: %s.', $type, implode(', ', self::ALLOWED_TYPES)),
            ]);
        }

        if ($rationale === '') {
            return $this->encode([
                'queued' => false,
                'reason' => 'missing_rationale',
                'message' => 'A plain-English rationale is required so a human can understand the proposal.',
            ]);
        }

        $subjectMismatch = $this->rejectForeignSeerrSubject($type, $payload, $context);
        if ($subjectMismatch !== null) {
            return $this->encode($subjectMismatch);
        }

        $replacementConflict = $this->rejectMonitorDuringReplacement($type, $payload);
        if ($replacementConflict !== null) {
            return $this->encode($replacementConflict);
        }

        try {
            $actionRequest = resolve(ActionOrchestrator::class)->dispatchFromAgent(
                type: $type,
                sourceService: $context->sourceService,
                targetService: $targetService !== '' ? $targetService : $context->sourceService,
                payload: $payload,
                rationale: Str::limit($rationale, 1000, ''),
                webhookEventId: $context->webhookEventId,
                forceRequiresApproval: in_array($type, self::FORCED_APPROVAL_TYPES, true) ? true : null,
            );
        } catch (Throwable $throwable) {
            Log::warning('ProposeActionTool: dispatch failed', [
                'type' => $type,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return $this->encode([
                'queued' => false,
                'reason' => 'dispatch_failed',
                'message' => 'Could not queue the action. Do not retry the identical call.',
            ]);
        }

        if ($actionRequest === null) {
            return $this->encode([
                'queued' => false,
                'reason' => 'no_action_type_config',
                'message' => sprintf('No enabled Action Rule exists for "%s". It cannot be queued until an admin enables it.', $type),
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
                ? 'Queued as a suggestion pending human approval.'
                : 'Queued and will auto-execute.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->description('The action type to propose. One of: '.implode(', ', self::ALLOWED_TYPES).'.')
                ->required(),
            'target_service' => $schema->string()
                ->description('The service the action targets, e.g. "sonarr", "radarr", "emby", "seerr".')
                ->required(),
            'rationale' => $schema->string()
                ->description('Plain-English justification shown to the human approver: what triggered this and why this action.')
                ->required(),
            'payload' => $schema->object([])
                ->description('Action-specific arguments (e.g. {"series_id": 42, "delete_files": true}). Use IDs from the event payload or read tools — never invent them.'),
        ];
    }

    /**
     * A Seerr-mutating proposal may only target the request that triggered
     * this run. Without this, injected payload text could steer the agent
     * into approving/declining an unrelated (e.g. the attacker's own) Seerr
     * request id.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null structured rejection, or null when OK
     */
    private function rejectForeignSeerrSubject(string $type, array $payload, DecisionRunContext $decisionRunContext): ?array
    {
        if (! in_array($type, self::FORCED_APPROVAL_TYPES, true)) {
            return null;
        }

        $proposedId = (int) ($payload['seerr_request_id'] ?? 0);

        $eventRequestId = $decisionRunContext->webhookEventId === null
            ? null
            : (int) (WebhookEvent::query()->find($decisionRunContext->webhookEventId)?->payload['request']['request_id'] ?? 0);

        if ($eventRequestId === null || $eventRequestId <= 0) {
            return [
                'queued' => false,
                'reason' => 'subject_not_verifiable',
                'message' => 'The triggering event carries no Seerr request id, so Seerr request mutations cannot be proposed from it.',
            ];
        }

        if ($proposedId !== $eventRequestId) {
            return [
                'queued' => false,
                'reason' => 'subject_mismatch',
                'message' => sprintf(
                    'seerr_request_id %d does not match the request that triggered this event (%d). Only the triggering request may be acted on.',
                    $proposedId,
                    $eventRequestId,
                ),
            ];
        }

        return null;
    }

    /**
     * The replacement executor deliberately unmonitors its target mid-run;
     * the resulting arr webhooks would otherwise let the agent "fix" the
     * monitoring flag and reopen the exact race the pipeline closed. Refuse
     * monitor proposals while a replacement attempt is in flight for the
     * same target.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null structured rejection, or null when OK
     */
    private function rejectMonitorDuringReplacement(string $type, array $payload): ?array
    {
        $targetKey = match ($type) {
            'monitor_series' => 'series_id',
            'monitor_movie' => 'movie_id',
            default => null,
        };

        if ($targetKey === null) {
            return null;
        }

        $targetId = (int) ($payload[$targetKey] ?? 0);

        if ($targetId <= 0) {
            return null;
        }

        $inFlight = MediaReplacementAttempt::query()
            ->whereNotIn('status', [
                MediaReplacementStatus::Verified->value,
                MediaReplacementStatus::Failed->value,
                MediaReplacementStatus::NeedsAttention->value,
            ])
            ->where('target->'.$targetKey, $targetId)
            ->exists();

        if (! $inFlight) {
            return null;
        }

        return [
            'queued' => false,
            'reason' => 'replacement_in_flight',
            'message' => sprintf(
                'A media replacement is in flight for this %s; its monitoring state is managed by the replacement pipeline and will be restored when it completes. Do not propose monitoring changes for it.',
                $targetKey === 'series_id' ? 'series' : 'movie',
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false
            ? '{"queued":false,"reason":"encoding_failed"}'
            : $encoded;
    }
}
