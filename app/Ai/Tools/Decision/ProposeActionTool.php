<?php

declare(strict_types=1);

namespace App\Ai\Tools\Decision;

use App\Ai\Decision\DecisionRunContext;
use App\Enums\MediaReplacementStatus;
use App\Models\MediaReplacementAttempt;
use App\Services\Actions\ActionDescriber;
use App\Services\Actions\ActionOrchestrator;
use App\Services\Actions\UndescribableAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * The DecisionAgent's only mutating tool. It hands a proposed action to the
 * ActionOrchestrator's agent path, which decides suggest-vs-act from the
 * per-type ActionTypeConfig.requires_approval flag (NOT the chat AiMode).
 *
 * Extends DecisionTool rather than BaseTool so it bypasses BaseTool's chat-advisory gate and auth()-bound action queueing.
 */
class ProposeActionTool extends DecisionTool
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
     * Seerr requests or deleting library media — an approval bypass if these
     * auto-executed. Forcing a human approval keeps prompt injection from
     * turning into real downloads, dropped requests or deleted files.
     */
    public const array FORCED_APPROVAL_TYPES = [
        'approve_seerr_request',
        'decline_seerr_request',
        'cleanup_seerr_request',
        'delete_series',
        'delete_movie',
    ];

    /**
     * Seerr request mutations: they may only target the request that
     * triggered the run.
     */
    private const array SEERR_REQUEST_TYPES = [
        'approve_seerr_request',
        'decline_seerr_request',
        'cleanup_seerr_request',
    ];

    /**
     * Series/movie-scoped types mapped to the event subject they must match
     * and the payload key their executor (SonarrActions / RadarrActions)
     * reads the target id from.
     *
     * @var array<string, array{subject: 'series'|'movie', key: string}>
     */
    private const array SUBJECT_BOUND_TYPES = [
        'delete_series' => ['subject' => 'series', 'key' => 'sonarr_series_id'],
        'monitor_series' => ['subject' => 'series', 'key' => 'series_id'],
        'set_series_quality_profile' => ['subject' => 'series', 'key' => 'series_id'],
        'delete_movie' => ['subject' => 'movie', 'key' => 'radarr_movie_id'],
        'monitor_movie' => ['subject' => 'movie', 'key' => 'movie_id'],
        'set_movie_quality_profile' => ['subject' => 'movie', 'key' => 'movie_id'],
    ];

    public function description(): Stringable|string
    {
        return 'Propose ONE concrete action in response to the inbound event. Suggest-vs-act is decided by admin rules, not by you. Call this once per distinct action you want taken (subject to a per-run cap). If no action is warranted, do NOT call this — just explain your reasoning in your final reply. Never guess IDs; rely on the event payload and read tools.';
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(Request $request): array
    {
        $decisionRunContext = $this->boundRunContext();
        $args = $request->toArray();
        $type = (string) ($args['type'] ?? '');

        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            return [
                'queued' => false,
                'reason' => 'type_not_allowed',
                'message' => sprintf('"%s" is not a proposable action type. Allowed: %s.', $type, implode(', ', self::ALLOWED_TYPES)),
            ];
        }

        $validated = $request->validate([
            'rationale' => ['required', 'string'],
        ], [
            'rationale.required' => 'A plain-English rationale is required so a human can understand the proposal.',
            'rationale.string' => 'rationale must be plain-English text so a human can understand the proposal.',
        ]);

        $targetService = (string) ($args['target_service'] ?? '');
        $rationale = (string) $validated['rationale'];
        $payload = is_array($args['payload'] ?? null) ? $args['payload'] : [];

        $subjectMismatch = $this->rejectForeignSeerrSubject($type, $payload, $decisionRunContext)
            ?? $this->rejectForeignMediaSubject($type, $payload, $decisionRunContext);

        if ($subjectMismatch !== null) {
            return $subjectMismatch;
        }

        $replacementConflict = $this->rejectMonitorDuringReplacement($type, $payload);

        if ($replacementConflict !== null) {
            return $replacementConflict;
        }

        try {
            $description = resolve(ActionDescriber::class)
                ->describe($type, $decisionRunContext->pinContext($payload), is_string($args['title'] ?? null) ? $args['title'] : null)
                ->because($decisionRunContext->proposalReason());
        } catch (UndescribableAction $undescribableAction) {
            return [
                'queued' => false,
                'reason' => 'missing_target',
                'message' => sprintf('%s Take the id from the event payload or a read tool and propose again.', $undescribableAction->getMessage()),
            ];
        }

        try {
            $actionRequest = resolve(ActionOrchestrator::class)->dispatchFromAgent(
                type: $type,
                sourceService: $decisionRunContext->sourceService,
                targetService: $targetService !== '' ? $targetService : $decisionRunContext->sourceService,
                payload: $payload,
                rationale: Str::limit($rationale, 1000, ''),
                description: $description,
                webhookEventId: $decisionRunContext->webhookEventId,
                forceRequiresApproval: in_array($type, self::FORCED_APPROVAL_TYPES, true) ? true : null,
                pinnedConnectionId: $decisionRunContext->originConnectionId,
            );
        } catch (Throwable $throwable) {
            Log::warning('ProposeActionTool: dispatch failed', [
                'type' => $type,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return [
                'queued' => false,
                'reason' => 'dispatch_failed',
                'message' => 'Could not queue the action. Do not retry the identical call.',
            ];
        }

        if ($actionRequest === null) {
            return [
                'queued' => false,
                'reason' => 'no_action_type_config',
                'message' => sprintf('No enabled Action Rule exists for "%s". It cannot be queued until an admin enables it.', $type),
            ];
        }

        $decisionRunContext->recordQueued($actionRequest->id, $actionRequest->requires_approval);

        return [
            'queued' => true,
            'action_request_id' => $actionRequest->id,
            'status' => $actionRequest->status->value,
            'requires_approval' => $actionRequest->requires_approval,
            'remaining_budget' => $decisionRunContext->remainingBudget(),
            'message' => $actionRequest->requires_approval
                ? 'Queued as a suggestion pending human approval.'
                : 'Queued and will auto-execute.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function noActiveRunRejection(): array
    {
        return [
            'queued' => false,
            'reason' => 'no_active_run',
            'message' => 'No active decision run; cannot propose actions.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function capReachedRejection(): array
    {
        return [
            'queued' => false,
            'reason' => 'max_actions_reached',
            'message' => 'The per-run action cap has been reached. Do not propose further actions; summarize what you have queued.',
        ];
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
                ->description('Action-specific arguments (e.g. {"sonarr_series_id": 42, "delete_files": true} for delete_series). Use IDs from the event payload or read tools — never invent them.'),
            'title' => $schema->string()
                ->description('Optional human name of the target (e.g. the series title). Only shown if the server cannot resolve the id from the payload; such proposals always wait for human approval.'),
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
        if (! in_array($type, self::SEERR_REQUEST_TYPES, true)) {
            return null;
        }

        $proposedId = (int) ($payload['seerr_request_id'] ?? 0);

        $eventRequestId = (int) ($decisionRunContext->eventPayload['request']['request_id'] ?? 0);

        if ($eventRequestId <= 0) {
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
     * A series/movie-scoped proposal may only target the subject of the event
     * that triggered this run. Without this, injected payload text could
     * steer the agent into deleting or re-configuring an unrelated title.
     * The id is read from the exact key the executor acts on, so a matching
     * id smuggled under another key cannot pass the check. A missing or
     * malformed id is left to the describer, which rejects it as
     * missing_target. When the event names no subject, only the destructive
     * delete types are refused; monitor/quality changes stay subject to the
     * admin's action rules.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null structured rejection, or null when OK
     */
    private function rejectForeignMediaSubject(string $type, array $payload, DecisionRunContext $decisionRunContext): ?array
    {
        $binding = self::SUBJECT_BOUND_TYPES[$type] ?? null;

        if ($binding === null) {
            return null;
        }

        $proposedId = (int) ($payload[$binding['key']] ?? 0);

        if ($proposedId <= 0) {
            return null;
        }

        $eventSubjectId = (int) ($decisionRunContext->eventPayload[$binding['subject']]['id'] ?? 0);

        if ($eventSubjectId <= 0) {
            return str_starts_with($type, 'delete_')
                ? [
                    'queued' => false,
                    'reason' => 'subject_not_verifiable',
                    'message' => sprintf('The triggering event carries no %s id, so %s cannot be proposed from it.', $binding['subject'], $type),
                ]
                : null;
        }

        if ($proposedId !== $eventSubjectId) {
            return [
                'queued' => false,
                'reason' => 'subject_mismatch',
                'message' => sprintf(
                    '%s %d does not match the %s that triggered this event (%d). Only the triggering %s may be acted on.',
                    $binding['key'],
                    $proposedId,
                    $binding['subject'],
                    $eventSubjectId,
                    $binding['subject'],
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
}
