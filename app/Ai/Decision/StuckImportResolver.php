<?php

declare(strict_types=1);

namespace App\Ai\Decision;

use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Services\Actions\ActionDescriber;
use App\Services\Actions\ActionOrchestrator;
use App\Services\Arr\ArrConnections;
use App\Services\Arr\ManualImportResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queues the resolution of a stuck Sonarr/Radarr import — import it or remove
 * it — with every rail the DecisionAgent's tools apply: the subject binding,
 * the forced approval for partial imports and for every removal, connection
 * pinning and the run's action tally. The tools and the classifier fast path
 * both act through here. The manual-import capability check stays in the
 * callers (the tools' refusal hook and the fast path).
 */
final readonly class StuckImportResolver
{
    public function __construct(
        private ArrConnections $arrConnections,
        private ManualImportResolver $manualImportResolver,
        private ActionOrchestrator $actionOrchestrator,
        private ActionDescriber $actionDescriber,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(DecisionRunContext $decisionRunContext, string $service, string $downloadId): array
    {
        $type = $service === 'sonarr' ? ServiceType::Sonarr : ServiceType::Radarr;

        $subjectMismatch = $this->rejectForeignDownload($downloadId, $decisionRunContext, 'imported');

        if ($subjectMismatch !== null) {
            return $subjectMismatch;
        }

        try {
            $connection = $decisionRunContext->resolveConnection($type);
            $candidates = $this->arrConnections->client($connection)->getManualImport(['downloadId' => $downloadId]);
        } catch (Throwable $throwable) {
            Log::warning('ResolveManualImportTool: candidate lookup failed', [
                'service' => $service,
                'download_id' => $downloadId,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return ['queued' => false, 'reason' => 'lookup_failed', 'message' => 'Could not enumerate import candidates. Note this and do not retry the identical call.'];
        }

        $assessment = $this->manualImportResolver->assess($candidates, $service, $downloadId);

        if ($assessment['importable'] === 0) {
            return [
                'queued' => false,
                'reason' => 'nothing_importable',
                'assessment' => $assessment,
                'message' => 'No candidate could be mapped to a series/movie. A human must resolve this in Sonarr/Radarr. Explain this in your summary.',
            ];
        }

        // Structural safety rail: a partial/unmapped set is never auto-imported,
        // regardless of the action rule or the caller's judgement.
        $partial = ! $assessment['fully_mapped'];
        $rationale = $this->buildRationale($service, $downloadId, $assessment, $partial);

        try {
            $actionPayload = ['service' => $service, 'download_id' => $downloadId, 'assessment' => $assessment];

            $actionRequest = $this->actionOrchestrator->dispatchFromAgent(
                type: 'resolve_manual_import',
                sourceService: $service,
                targetService: $service,
                payload: $actionPayload,
                rationale: $rationale,
                description: $this->actionDescriber
                    ->describe('resolve_manual_import', $decisionRunContext->pinContext($actionPayload))
                    ->because($decisionRunContext->proposalReason()),
                webhookEventId: $decisionRunContext->webhookEventId,
                forceRequiresApproval: $partial ? true : null,
                pinnedConnectionId: $decisionRunContext->originConnectionId,
            );
        } catch (Throwable $throwable) {
            Log::warning('ResolveManualImportTool: dispatch failed', [
                'service' => $service,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return ['queued' => false, 'reason' => 'dispatch_failed'];
        }

        if (! $actionRequest instanceof ActionRequest) {
            return [
                'queued' => false,
                'reason' => 'no_action_type_config',
                'message' => 'The resolve_manual_import Action Rule is missing or disabled; an admin must enable it.',
            ];
        }

        $decisionRunContext->recordQueued($actionRequest->id, $actionRequest->requires_approval);

        return [
            'queued' => true,
            'action_request_id' => $actionRequest->id,
            'status' => $actionRequest->status->value,
            'requires_approval' => $actionRequest->requires_approval,
            'partial' => $partial,
            'assessment' => $assessment,
            'remaining_budget' => $decisionRunContext->remainingBudget(),
            'message' => $partial
                ? 'Only some files mapped — queued for human approval.'
                : ($actionRequest->requires_approval
                    ? 'Import queued for human approval (per action rule).'
                    : 'Import queued and will auto-run.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function remove(DecisionRunContext $decisionRunContext, string $service, string $downloadId, string $reason, bool $blocklist, bool $searchReplacement): array
    {
        $subjectMismatch = $this->rejectForeignDownload($downloadId, $decisionRunContext, 'removed');

        if ($subjectMismatch !== null) {
            return $subjectMismatch;
        }

        try {
            $actionPayload = ['service' => $service, 'download_id' => $downloadId, 'blocklist' => $blocklist, 'search_replacement' => $searchReplacement];

            $actionRequest = $this->actionOrchestrator->dispatchFromAgent(
                type: 'remove_stuck_download',
                sourceService: $service,
                targetService: $service,
                payload: $actionPayload,
                rationale: Str::limit(sprintf('Remove stuck %s download %s: %s', $service, $downloadId, $reason), 1000, ''),
                description: $this->actionDescriber
                    ->describe('remove_stuck_download', $decisionRunContext->pinContext($actionPayload))
                    ->because($decisionRunContext->proposalReason()),
                webhookEventId: $decisionRunContext->webhookEventId,
                forceRequiresApproval: true,
                pinnedConnectionId: $decisionRunContext->originConnectionId,
            );
        } catch (Throwable $throwable) {
            Log::warning('RemoveStuckDownloadTool: dispatch failed', [
                'service' => $service,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return ['queued' => false, 'reason' => 'dispatch_failed'];
        }

        if (! $actionRequest instanceof ActionRequest) {
            return [
                'queued' => false,
                'reason' => 'no_action_type_config',
                'message' => 'The remove_stuck_download Action Rule is missing or disabled; an admin must enable it.',
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
                ? 'Removal queued for human approval.'
                : 'Removal queued and will auto-run.',
        ];
    }

    /**
     * @param  array{total: int, importable: int, fully_mapped: bool, reasons: array<int, string>}  $assessment
     */
    private function buildRationale(string $service, string $downloadId, array $assessment, bool $partial): string
    {
        $reasons = $assessment['reasons'] === [] ? 'all files mapped' : implode(' ', $assessment['reasons']);

        return Str::limit(sprintf(
            'Resolve stuck %s import (download %s): %d of %d files mapped. %s %s',
            $service,
            $downloadId,
            $assessment['importable'],
            $assessment['total'],
            $partial ? 'Partial — recommend manual confirmation.' : 'Fully mapped.',
            $reasons,
        ), 1000, '');
    }

    /**
     * An action may only target the download that triggered this run when
     * the event names one, so injected payload text can't steer the agent
     * into acting on an unrelated download.
     *
     * @return array<string, mixed>|null structured rejection, or null when OK
     */
    private function rejectForeignDownload(string $downloadId, DecisionRunContext $decisionRunContext, string $verb): ?array
    {
        $eventDownloadId = $decisionRunContext->eventDownloadId();

        if ($eventDownloadId === null || $eventDownloadId === $downloadId) {
            return null;
        }

        return [
            'queued' => false,
            'reason' => 'subject_mismatch',
            'message' => sprintf(
                'download_id %s does not match the download that triggered this event (%s). Only the triggering download may be %s.',
                $downloadId,
                $eventDownloadId,
                $verb,
            ),
        ];
    }
}
