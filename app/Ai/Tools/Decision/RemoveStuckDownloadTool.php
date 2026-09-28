<?php

declare(strict_types=1);

namespace App\Ai\Tools\Decision;

use App\Ai\Decision\DecisionRunContext;
use App\Services\Actions\ActionDescriber;
use App\Services\Actions\ActionOrchestrator;
use App\Settings\DecisionAgentSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Removes a stuck Sonarr/Radarr download from the queue — the resolution for a
 * stuck import the agent decides shouldn't be imported (e.g. "not an upgrade for
 * existing episode file(s)"). Optionally blocklists the release (payload.blocklist)
 * when the release itself is bad so the arr never grabs it again, and/or triggers
 * an immediate search for a replacement release (payload.search_replacement).
 *
 * Gated behind the same manual-import capability as importing. Removals are
 * always queued for human approval regardless of the remove_stuck_download
 * action rule, since this deletes the downloaded data and the agent's prompt
 * embeds third-party-authored webhook text. Extends DecisionTool, not BaseTool:
 * it owns its dispatch path through dispatchFromAgent().
 */
class RemoveStuckDownloadTool extends DecisionTool
{
    public function description(): Stringable|string
    {
        return 'Remove a stuck Sonarr/Radarr download from the queue — use when an inspected stuck import should NOT be imported, e.g. it is "not an upgrade for existing episode file(s)". Provide the service, the download_id, and a short reason. Optionally pass blocklist=true to also blocklist the release so the arr never grabs it again (only when the release itself is bad — corrupt/fake/wrong content), and/or search_replacement=true to have the arr immediately search for a replacement release after removal (combine with blocklist=true to retry with a different release). This deletes the downloaded data and always requires human approval.';
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function refusal(): ?array
    {
        if (resolve(DecisionAgentSettings::class)->allowManualImport()) {
            return null;
        }

        return [
            'queued' => false,
            'reason' => 'capability_disabled',
            'message' => 'Manual-import resolution is disabled in Decision Agent settings; you cannot remove stuck downloads. Note this in your summary.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(Request $request): array
    {
        $context = $this->boundRunContext();
        $validated = $request->validate([
            'service' => ['required', 'string', 'regex:/^(sonarr|radarr)$/Di'],
            'download_id' => ['required', 'string'],
            'reason' => ['required', 'string'],
        ], [
            'service.required' => 'service must be "sonarr" or "radarr".',
            'service.regex' => 'service must be "sonarr" or "radarr".',
            'download_id.required' => 'download_id is required.',
            'reason.required' => 'A short reason is required so the human approver understands why.',
        ]);
        $args = $request->toArray();
        $service = mb_strtolower((string) $validated['service']);
        $downloadId = (string) $validated['download_id'];
        $reason = (string) $validated['reason'];
        $blocklist = ($args['blocklist'] ?? null) === true;
        $searchReplacement = ($args['search_replacement'] ?? null) === true;

        $subjectMismatch = $this->rejectForeignDownload($downloadId, $context);

        if ($subjectMismatch !== null) {
            return $subjectMismatch;
        }

        try {
            $actionPayload = ['service' => $service, 'download_id' => $downloadId, 'blocklist' => $blocklist, 'search_replacement' => $searchReplacement];

            $actionRequest = resolve(ActionOrchestrator::class)->dispatchFromAgent(
                type: 'remove_stuck_download',
                sourceService: $service,
                targetService: $service,
                payload: $actionPayload,
                rationale: Str::limit(sprintf('Remove stuck %s download %s: %s', $service, $downloadId, $reason), 1000, ''),
                description: resolve(ActionDescriber::class)
                    ->describe('remove_stuck_download', $context->pinContext($actionPayload))
                    ->because($context->proposalReason()),
                webhookEventId: $context->webhookEventId,
                forceRequiresApproval: true,
                pinnedConnectionId: $context->originConnectionId,
            );
        } catch (Throwable $throwable) {
            Log::warning('RemoveStuckDownloadTool: dispatch failed', [
                'service' => $service,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return ['queued' => false, 'reason' => 'dispatch_failed'];
        }

        if ($actionRequest === null) {
            return [
                'queued' => false,
                'reason' => 'no_action_type_config',
                'message' => 'The remove_stuck_download Action Rule is missing or disabled; an admin must enable it.',
            ];
        }

        $context->recordQueued($actionRequest->id, $actionRequest->requires_approval);

        return [
            'queued' => true,
            'action_request_id' => $actionRequest->id,
            'status' => $actionRequest->status->value,
            'requires_approval' => $actionRequest->requires_approval,
            'remaining_budget' => $context->remainingBudget(),
            'message' => $actionRequest->requires_approval
                ? 'Removal queued for human approval.'
                : 'Removal queued and will auto-run.',
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
            'reason' => $schema->string()
                ->description('Short plain-English reason for removing rather than importing (e.g. "not an upgrade for existing file").')
                ->required(),
            'blocklist' => $schema->boolean()
                ->description('Also blocklist the release so the arr never grabs it again. Use when the release itself is bad (corrupt, fake, wrong content) — not when it merely isn\'t an upgrade. Default false.')
                ->required()
                ->nullable(),
            'search_replacement' => $schema->boolean()
                ->description('After removing, have the arr immediately search for a replacement release. Combine with blocklist=true to retry with a different release; leave false when the content should not be re-grabbed at all. Default false.')
                ->required()
                ->nullable(),
        ];
    }

    /**
     * A removal may only target the download that triggered this run when the
     * event names one. Without this, injected payload text could steer the
     * agent into deleting an unrelated download's data. The event's id is read
     * the way the arr webhook handlers read it: top-level downloadId, falling
     * back to downloadInfo.downloadId.
     *
     * @return array<string, mixed>|null structured rejection, or null when OK
     */
    private function rejectForeignDownload(string $downloadId, DecisionRunContext $decisionRunContext): ?array
    {
        $eventDownloadId = $decisionRunContext->eventDownloadId();

        if ($eventDownloadId === null || $eventDownloadId === $downloadId) {
            return null;
        }

        return [
            'queued' => false,
            'reason' => 'subject_mismatch',
            'message' => sprintf(
                'download_id %s does not match the download that triggered this event (%s). Only the triggering download may be removed.',
                $downloadId,
                $eventDownloadId,
            ),
        ];
    }
}
