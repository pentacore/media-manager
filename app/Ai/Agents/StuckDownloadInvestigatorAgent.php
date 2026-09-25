<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Decision\InspectStuckImportTool;
use App\Ai\Middleware\AnswerOnFinalStep;
use App\Ai\Middleware\EnforceBudgetEachStep;
use App\Ai\Tools\Arr\GetDownloadHistoryTool;
use App\Ai\Tools\Arr\GetDownloadQueueTool;
use App\Settings\AiSettings;
use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\RepairToolCalls;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;

/**
 * Read-only investigation of a stuck Sonarr/Radarr/Whisparr download, run as
 * a MediaAgent sub-agent. It never imports or removes anything — MediaAgent
 * acts on the structured findings through its own destructive tools.
 */
#[MaxSteps(8)]
#[RepairToolCalls]
final class StuckDownloadInvestigatorAgent implements Agent, CanActAsTool, HasMiddleware, HasStructuredOutput, HasTools
{
    use Promptable;

    public function name(): string
    {
        return 'InvestigateStuckDownload';
    }

    public function description(): string
    {
        return 'Investigates why a Sonarr/Radarr/Whisparr download is stuck and recommends import, remove or manual handling. Read-only. In the task, name the service and the download (download_id if known, otherwise the title).';
    }

    public function model(): string
    {
        return resolve(AiSettings::class)->subAgentModel();
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

    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => $schema->string()->enum(['sonarr', 'radarr', 'whisparr'])->required(),
            'download_id' => $schema->string()->description('Exactly as returned by the tools.')->required(),
            'title' => $schema->string()->required(),
            'files' => $schema->array()->items($schema->string())->description('One line per candidate file: "<path> | mapped|unmapped | <rejections>".')->required(),
            'recommendation' => $schema->string()->enum(['import', 'remove', 'manual'])->required(),
            'blocklist' => $schema->boolean()->required(),
            'search_replacement' => $schema->boolean()->required(),
            'reason' => $schema->string()->description('One or two plain-language sentences for the user.')->required(),
        ];
    }

    /**
     * Wrap every generation step: refuse a step once the hard budget is
     * crossed mid-run, and force a plain answer on the final allowed step.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new AnswerOnFinalStep, new EnforceBudgetEachStep];
    }

    /**
     * laravel/ai 1.0 refuses to stream structured output, and a streamed
     * parent run delegates through AgentTool::stream(). Run the structured
     * prompt instead and hand its JSON back as the stream's only text, so
     * the parent receives the findings on the streaming path too.
     *
     * @param  array<int, mixed>  $attachments
     */
    public function stream(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
    {
        $meta = new Meta;

        $streamableAgentResponse = new StreamableAgentResponse((string) Str::uuid7(), function () use (&$streamableAgentResponse, $meta, $prompt, $attachments, $provider, $model, $timeout): Generator {
            $agentResponse = $this->prompt($prompt, $attachments, $provider, $model, $timeout);

            $streamableAgentResponse->invocationId = $agentResponse->invocationId;
            $meta->provider = $agentResponse->meta->provider;
            $meta->model = $agentResponse->meta->model;

            yield new TextDelta(Str::lower((string) Str::uuid7()), Str::lower((string) Str::uuid7()), $agentResponse->text, time());
        }, $meta);

        return $streamableAgentResponse;
    }
}
