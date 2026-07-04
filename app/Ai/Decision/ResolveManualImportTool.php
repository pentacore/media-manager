<?php

declare(strict_types=1);

namespace App\Ai\Decision;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionOrchestrator;
use App\Services\Arr\ManualImportResolver;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
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
 * Resolves a Sonarr/Radarr "manual interaction required" stuck import.
 *
 * Gated behind DecisionAgentSettings::allowManualImport(). It enumerates the
 * download's candidate files and proposes a resolve_manual_import action.
 * Partially-mapped sets are force-queued for human approval regardless of the
 * action rule's auto-execute setting; fully-mapped imports follow the rule.
 * Interpreting rejection text (import vs remove) is the agent's job, not this
 * tool's — see InspectStuckImportTool / RemoveStuckDownloadTool.
 *
 * Lives outside App\Ai\Tools (and so does not extend BaseTool) because it must
 * own its own dispatch path — BaseTool routes destructive work through the
 * chat-advisory gate and an authenticated user, neither of which applies to a
 * background agent.
 */
class ResolveManualImportTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Import a stuck Sonarr/Radarr download (after inspecting it with InspectStuckImportTool). Provide the service and download_id. Fully-mapped imports may auto-run per the action rule; partially-mapped sets are always queued for human approval. If a download should NOT be imported (e.g. "not an upgrade"), use RemoveStuckDownloadTool instead.';
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
                'message' => 'Manual-import resolution is disabled in Decision Agent settings. Note this in your summary; do not propose other destructive actions to work around it.',
            ]);
        }

        if ($context->capReached()) {
            return $this->encode(['queued' => false, 'reason' => 'max_actions_reached']);
        }

        $args = $request->toArray();
        $service = mb_strtolower((string) ($args['service'] ?? ''));
        $downloadId = (string) ($args['download_id'] ?? '');

        $type = match ($service) {
            'sonarr' => ServiceType::Sonarr,
            'radarr' => ServiceType::Radarr,
            default => null,
        };
        if ($type === null) {
            return $this->encode(['queued' => false, 'reason' => 'invalid_service', 'message' => 'service must be "sonarr" or "radarr".']);
        }

        if ($downloadId === '') {
            return $this->encode(['queued' => false, 'reason' => 'missing_download_id', 'message' => 'download_id is required (take it from the event payload).']);
        }

        try {
            $connection = ServiceConnection::resolveActive($type);
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

            return $this->encode(['queued' => false, 'reason' => 'lookup_failed', 'message' => 'Could not enumerate import candidates. Note this and do not retry the identical call.']);
        }

        $assessment = resolve(ManualImportResolver::class)->assess($candidates, $service, $downloadId);

        if ($assessment['importable'] === 0) {
            return $this->encode([
                'queued' => false,
                'reason' => 'nothing_importable',
                'assessment' => $assessment,
                'message' => 'No candidate could be mapped to a series/movie. A human must resolve this in Sonarr/Radarr. Explain this in your summary.',
            ]);
        }

        // Structural safety rail: a partial/unmapped set is never auto-imported,
        // regardless of the action rule or the agent's judgement.
        $partial = ! $assessment['fully_mapped'];
        $rationale = $this->buildRationale($service, $downloadId, $assessment, $partial);

        try {
            $actionRequest = resolve(ActionOrchestrator::class)->dispatchFromAgent(
                type: 'resolve_manual_import',
                sourceService: $service,
                targetService: $service,
                payload: [
                    'service' => $service,
                    'download_id' => $downloadId,
                    'assessment' => $assessment,
                ],
                rationale: $rationale,
                webhookEventId: $context->webhookEventId,
                forceRequiresApproval: $partial ? true : null,
            );
        } catch (Throwable $throwable) {
            Log::warning('ResolveManualImportTool: dispatch failed', [
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
                'message' => 'The resolve_manual_import Action Rule is missing or disabled; an admin must enable it.',
            ]);
        }

        $context->recordQueued($actionRequest->id, $actionRequest->requires_approval);

        return $this->encode([
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
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? '{"queued":false,"reason":"encoding_failed"}' : $encoded;
    }
}
