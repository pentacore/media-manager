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
 * download's candidate files, assesses whether the mapping is unambiguous, and
 * proposes a resolve_manual_import action. Ambiguous imports (partial mappings,
 * upstream rejections) are force-queued for human approval regardless of the
 * action rule's auto-execute setting; clean imports follow the rule.
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
        return 'Resolve a stuck Sonarr/Radarr import (a "manual interaction required" event). Provide the download_id from the event payload and the service. The tool inspects the candidate files and proposes the import: unambiguous imports may auto-run, ambiguous ones are queued for human approval. Use this for ManualInteractionRequired events instead of ProposeActionTool.';
    }

    public function handle(Request $request): Stringable|string
    {
        $context = app()->bound(DecisionRunContext::class) ? app(DecisionRunContext::class) : null;
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

        $ambiguous = $assessment['ambiguous'];
        $rationale = $this->buildRationale($service, $downloadId, $assessment, $ambiguous);

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
                forceRequiresApproval: $ambiguous ? true : null,
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
            'ambiguous' => $ambiguous,
            'assessment' => $assessment,
            'remaining_budget' => $context->remainingBudget(),
            'message' => $ambiguous
                ? 'Import is ambiguous — queued for human approval.'
                : ($actionRequest->requires_approval
                    ? 'Clean import queued for human approval (per action rule).'
                    : 'Clean import queued and will auto-run.'),
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
     * @param  array{total: int, importable: int, rejected: int, ambiguous: bool, reasons: array<int, string>}  $assessment
     */
    private function buildRationale(string $service, string $downloadId, array $assessment, bool $ambiguous): string
    {
        $reasons = $assessment['reasons'] === [] ? 'clean mapping, no rejections' : implode(' ', $assessment['reasons']);

        return Str::limit(sprintf(
            'Resolve stuck %s import (download %s): %d of %d files importable, %d rejected. %s %s',
            $service,
            $downloadId,
            $assessment['importable'],
            $assessment['total'],
            $assessment['rejected'],
            $ambiguous ? 'Ambiguous — recommend manual confirmation.' : 'Unambiguous.',
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
