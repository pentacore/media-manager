<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\ActsAsStructuredSubAgent;
use App\Ai\Concerns\RunsAsAiTask;
use App\Ai\Concerns\UsesFailoverChain;
use App\Ai\Tools\Arr\GetDownloadHistoryTool;
use App\Ai\Tools\Arr\GetDownloadQueueTool;
use App\Ai\Tools\Decision\InspectStuckImportTool;
use App\Enums\AiTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\RepairToolCalls;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

/**
 * Read-only investigation of stuck Sonarr/Radarr downloads (one or many per call), run as a
 * MediaAgent sub-agent. It never imports or removes anything — MediaAgent
 * acts on the structured findings through its own destructive tools.
 */
#[MaxSteps(10)]
#[RepairToolCalls]
final class StuckDownloadInvestigatorAgent implements Agent, CanActAsTool, HasMiddleware, HasProviderOptions, HasStructuredOutput, HasTools
{
    use ActsAsStructuredSubAgent;
    use Promptable;
    use RunsAsAiTask;
    use UsesFailoverChain;

    public function name(): string
    {
        return 'InvestigateStuckDownload';
    }

    public function description(): string
    {
        return 'Investigates one or more stuck Sonarr/Radarr downloads in a single call and recommends import, remove or manual handling for each. Read-only. In the task, list EVERY download to look at (service and download_id if known, otherwise the title) — never call this once per download.';
    }

    public function aiTask(): AiTask
    {
        return AiTask::StuckDownloadInvestigator;
    }

    public function instructions(): string
    {
        return <<<'PROMPT'
You investigate stuck downloads for a media stack and report findings, one entry per download. You cannot change anything.

1. For downloads without a download_id, find the ids with ONE GetDownloadQueueTool call (stuck_only=true) and match by title.
2. Call InspectStuckImportTool ONCE per service with all of that service's `download_ids` (the Sonarr and Radarr calls may go in the same step). It takes at most 20 download_ids per call: split a larger set into calls of up to 20 per service. Never call it once per download. Use GetDownloadHistoryTool only if a queue entry is gone.
3. For each download, recommend:
   - import: files map and the only rejection is benign (e.g. "matched by series id", "automatic import is not possible").
   - remove: the blocking rejection says it is not an upgrade / not a Custom Format upgrade. Set blocklist=true only if the release itself is bad (corrupt, fake, wrong content). Set search_replacement=true only if the content is still wanted.
   - manual: nothing maps, or you are unsure.
4. Samples: put the path of a sample file in `excluded_paths` and do not let it change the recommendation. `excluded_paths` is empty when there is nothing to leave out.
Never invent IDs. Copy each download_id exactly as the tools returned it. List every candidate file as "<path> | mapped|unmapped | <rejections>".
PROMPT;
    }

    /**
     * @return iterable<int, Tool>
     */
    public function tools(): iterable
    {
        return [
            resolve(GetDownloadQueueTool::class),
            resolve(GetDownloadHistoryTool::class),
            resolve(InspectStuckImportTool::class),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'downloads' => $schema->array()->items($schema->object([
                'service' => $schema->string()->enum(['sonarr', 'radarr'])->required(),
                'download_id' => $schema->string()->description('Exactly as returned by the tools.')->required(),
                'title' => $schema->string()->required(),
                'files' => $schema->array()->items($schema->string())->description('One line per candidate file: "<path> | mapped|unmapped | <rejections>".')->required(),
                'recommendation' => $schema->string()->enum(['import', 'remove', 'manual'])->required(),
                'blocklist' => $schema->boolean()->required(),
                'search_replacement' => $schema->boolean()->required(),
                'excluded_paths' => $schema->array()->items($schema->string())->description('Files to leave out of an import, such as samples. Empty when none.')->required(),
                'reason' => $schema->string()->description('One or two plain-language sentences for the user.')->required(),
            ]))->description('One entry per investigated download.')->required(),
        ];
    }
}
