<?php

declare(strict_types=1);

namespace App\Ai\Decision;

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

    public function description(): Stringable|string
    {
        return 'Propose ONE concrete action in response to the inbound event. Suggest-vs-act is decided by admin rules, not by you. Call this once per distinct action you want taken (subject to a per-run cap). If no action is warranted, do NOT call this — just explain your reasoning in your final reply. Never guess IDs; rely on the event payload and read tools.';
    }

    public function handle(Request $request): string
    {
        $context = app()->bound(DecisionRunContext::class)
            ? app(DecisionRunContext::class)
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

        try {
            $actionRequest = resolve(ActionOrchestrator::class)->dispatchFromAgent(
                type: $type,
                sourceService: $context->sourceService,
                targetService: $targetService !== '' ? $targetService : $context->sourceService,
                payload: $payload,
                rationale: Str::limit($rationale, 1000, ''),
                webhookEventId: $context->webhookEventId,
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
