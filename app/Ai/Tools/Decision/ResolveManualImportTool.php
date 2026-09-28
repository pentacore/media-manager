<?php

declare(strict_types=1);

namespace App\Ai\Tools\Decision;

use App\Ai\Decision\DecisionRunContext;
use App\Enums\ServiceType;
use App\Services\Actions\ActionDescriber;
use App\Services\Actions\ActionOrchestrator;
use App\Services\Arr\ManualImportResolver;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use App\Settings\DecisionAgentSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Resolves a Sonarr/Radarr "manual interaction required" stuck import.
 *
 * Gated behind DecisionAgentSettings::allowManualImport(). It enumerates the
 * download's candidate files and proposes a resolve_manual_import action.
 * Partially-mapped sets are force-queued for human approval regardless of the
 * action rule's auto-execute setting; fully-mapped imports follow the rule.
 * Interpreting rejection text (import vs remove) is the agent's job, not this
 * tool's — see InspectStuckImportTool / RemoveStuckDownloadTool.
 *
 * Extends DecisionTool (not BaseTool) because it must own its own dispatch path — BaseTool routes destructive work through the
 * chat-advisory gate and an authenticated user, neither of which applies to a background agent.
 */
class ResolveManualImportTool extends DecisionTool
{
    public function description(): Stringable|string
    {
        return 'Import a stuck Sonarr/Radarr download (after inspecting it with InspectStuckImportTool). Provide the service and download_id. Fully-mapped imports may auto-run per the action rule; partially-mapped sets are always queued for human approval. If a download should NOT be imported (e.g. "not an upgrade"), use RemoveStuckDownloadTool instead.';
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
            'message' => 'Manual-import resolution is disabled in Decision Agent settings. Note this in your summary; do not propose other destructive actions to work around it.',
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
        ], [
            'service.required' => 'service must be "sonarr" or "radarr".',
            'service.regex' => 'service must be "sonarr" or "radarr".',
            'download_id.required' => 'download_id is required (take it from the event payload).',
        ]);
        $service = mb_strtolower((string) $validated['service']);
        $downloadId = (string) $validated['download_id'];
        $type = $service === 'sonarr' ? ServiceType::Sonarr : ServiceType::Radarr;

        $subjectMismatch = $this->rejectForeignDownload($downloadId, $context);

        if ($subjectMismatch !== null) {
            return $subjectMismatch;
        }

        try {
            $connection = $context->resolveConnection($type);
            $client = $type === ServiceType::Sonarr
                ? new SonarrClient($connection)
                : new RadarrClient($connection);
            $candidates = $client->getManualImport(['downloadId' => $downloadId]);
        } catch (Throwable $throwable) {
            Log::warning('ResolveManualImportTool: candidate lookup failed', [
                'service' => $service,
                'download_id' => $downloadId,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return ['queued' => false, 'reason' => 'lookup_failed', 'message' => 'Could not enumerate import candidates. Note this and do not retry the identical call.'];
        }

        $assessment = resolve(ManualImportResolver::class)->assess($candidates, $service, $downloadId);

        if ($assessment['importable'] === 0) {
            return [
                'queued' => false,
                'reason' => 'nothing_importable',
                'assessment' => $assessment,
                'message' => 'No candidate could be mapped to a series/movie. A human must resolve this in Sonarr/Radarr. Explain this in your summary.',
            ];
        }

        // Structural safety rail: a partial/unmapped set is never auto-imported,
        // regardless of the action rule or the agent's judgement.
        $partial = ! $assessment['fully_mapped'];
        $rationale = $this->buildRationale($service, $downloadId, $assessment, $partial);

        try {
            $actionPayload = ['service' => $service, 'download_id' => $downloadId, 'assessment' => $assessment];

            $actionRequest = resolve(ActionOrchestrator::class)->dispatchFromAgent(
                type: 'resolve_manual_import',
                sourceService: $service,
                targetService: $service,
                payload: $actionPayload,
                rationale: $rationale,
                description: resolve(ActionDescriber::class)
                    ->describe('resolve_manual_import', $context->pinContext($actionPayload))
                    ->because($context->proposalReason()),
                webhookEventId: $context->webhookEventId,
                forceRequiresApproval: $partial ? true : null,
                pinnedConnectionId: $context->originConnectionId,
            );
        } catch (Throwable $throwable) {
            Log::warning('ResolveManualImportTool: dispatch failed', [
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
                'message' => 'The resolve_manual_import Action Rule is missing or disabled; an admin must enable it.',
            ];
        }

        $context->recordQueued($actionRequest->id, $actionRequest->requires_approval);

        return [
            'queued' => true,
            'action_request_id' => $actionRequest->id,
            'status' => $actionRequest->status->value,
            'requires_approval' => $actionRequest->requires_approval,
            'partial' => $partial,
            'assessment' => $assessment,
            'remaining_budget' => $context->remainingBudget(),
            'message' => $partial
                ? 'Only some files mapped — queued for human approval.'
                : ($actionRequest->requires_approval
                    ? 'Import queued for human approval (per action rule).'
                    : 'Import queued and will auto-run.'),
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
                ->description('The downloadId from the ManualInteractionRequired event payload (download_id / downloadId field).')
                ->required(),
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
     * An import may only target the download that triggered this run when
     * the event names one — the same rail RemoveStuckDownloadTool applies,
     * so injected payload text can't steer the agent into importing an
     * unrelated download.
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
                'download_id %s does not match the download that triggered this event (%s). Only the triggering download may be imported.',
                $downloadId,
                $eventDownloadId,
            ),
        ];
    }
}
