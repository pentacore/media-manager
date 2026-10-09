<?php

declare(strict_types=1);

namespace App\Ai\Tools\Decision;

use App\Ai\Decision\StuckImportResolver;
use App\Settings\DecisionAgentSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

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
        $decisionRunContext = $this->boundRunContext();
        $validated = $request->validate([
            'service' => ['required', 'string', 'regex:/^(sonarr|radarr)$/Di'],
            'download_id' => ['required', 'string'],
        ], [
            'service.required' => 'service must be "sonarr" or "radarr".',
            'service.string' => 'service must be "sonarr" or "radarr".',
            'service.regex' => 'service must be "sonarr" or "radarr".',
            'download_id.required' => 'download_id is required (take it from the event payload).',
            'download_id.string' => 'download_id must be a string (take it from the event payload).',
        ]);
        $service = mb_strtolower((string) $validated['service']);
        $downloadId = (string) $validated['download_id'];

        return resolve(StuckImportResolver::class)->import($decisionRunContext, $service, $downloadId);
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
}
