<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Concerns\ActsAsStructuredSubAgent;
use App\Ai\Concerns\SendsOpenRouterOptions;
use App\Ai\Concerns\UsesFailoverChain;
use App\Ai\ModelSelection;
use App\Ai\Tools\Arr\GetDownloadHistoryTool;
use App\Ai\Tools\Arr\GetDownloadQueueTool;
use App\Ai\Tools\Decision\InspectStuckImportTool;
use App\Settings\AiSettings;
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
 * Read-only investigation of a stuck Sonarr/Radarr download, run as a
 * MediaAgent sub-agent. It never imports or removes anything — MediaAgent
 * acts on the structured findings through its own destructive tools.
 */
#[MaxSteps(8)]
#[RepairToolCalls]
final class StuckDownloadInvestigatorAgent implements Agent, CanActAsTool, HasMiddleware, HasProviderOptions, HasStructuredOutput, HasTools
{
    use ActsAsStructuredSubAgent, Promptable {
        ActsAsStructuredSubAgent::stream insteadof Promptable;
    }
    use SendsOpenRouterOptions;
    use UsesFailoverChain;

    public function name(): string
    {
        return 'InvestigateStuckDownload';
    }

    public function description(): string
    {
        return 'Investigates why a Sonarr/Radarr download is stuck and recommends import, remove or manual handling. Read-only. In the task, name the service (sonarr or radarr) and the download (download_id if known, otherwise the title).';
    }

    public function model(): string
    {
        return resolve(AiSettings::class)->subAgentModel();
    }

    public function modelSelection(): ModelSelection
    {
        return resolve(AiSettings::class)->subAgentSelection();
    }

    public function instructions(): string
    {
        return <<<'PROMPT'
You investigate one stuck download for a media stack and report findings. You cannot change anything.

1. If no download_id was given, find it with GetDownloadQueueTool (stuck_only=true) using the title.
2. Call InspectStuckImportTool with the service and download_id. Use GetDownloadHistoryTool only if the queue entry is gone.
3. Recommend:
   - import: files map and the only rejection is benign (e.g. "matched by series id", "automatic import is not possible").
   - remove: the blocking rejection says it is not an upgrade / not a Custom Format upgrade. Set blocklist=true only if the release itself is bad (corrupt, fake, wrong content). Set search_replacement=true only if the content is still wanted.
   - manual: nothing maps, or you are unsure.
Never invent IDs. Copy the download_id exactly as the tools returned it. List every candidate file as "<path> | mapped|unmapped | <rejections>".
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
            'service' => $schema->string()->enum(['sonarr', 'radarr'])->required(),
            'download_id' => $schema->string()->description('Exactly as returned by the tools.')->required(),
            'title' => $schema->string()->required(),
            'files' => $schema->array()->items($schema->string())->description('One line per candidate file: "<path> | mapped|unmapped | <rejections>".')->required(),
            'recommendation' => $schema->string()->enum(['import', 'remove', 'manual'])->required(),
            'blocklist' => $schema->boolean()->required(),
            'search_replacement' => $schema->boolean()->required(),
            'reason' => $schema->string()->description('One or two plain-language sentences for the user.')->required(),
        ];
    }
}
