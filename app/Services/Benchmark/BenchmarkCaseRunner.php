<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Ai\Agents\DecisionAgent;
use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\MediaFileInspectorAgent;
use App\Ai\Agents\PriceFetcherAgent;
use App\Ai\Agents\StuckDownloadInvestigatorAgent;
use App\Ai\Agents\TitleAgent;
use App\Ai\Benchmark\BenchmarkRunContext;
use App\Enums\AiTask;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkCaseAttachment;
use App\Services\AiUsage\Pricing\RefreshScope;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Ai\Files\File;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use LogicException;
use RuntimeException;

/**
 * Runs one benchmark case's agent once, exactly as production would invoke
 * it, inside a BenchmarkRunContext the caller already started (which pins the
 * candidate and answers every tool call from the case fixtures). Provider
 * failures propagate to the caller.
 */
final readonly class BenchmarkCaseRunner
{
    private const string PROVIDER_WEBFETCH_FLAG = 'mediamanager.ai.price_fetcher_provider_webfetch';

    public function __construct(private BenchmarkRunContext $benchmarkRunContext) {}

    public function run(BenchmarkCase $benchmarkCase): CaseOutcome
    {
        throw_unless($this->benchmarkRunContext->active(), LogicException::class, 'A benchmark case runs only inside an active benchmark run.');
        throw_unless($this->benchmarkRunContext->task() === $benchmarkCase->task, LogicException::class, sprintf(
            'The %s case does not belong to the active %s benchmark run.',
            $benchmarkCase->task->value,
            $this->benchmarkRunContext->task()?->value,
        ));

        $prompt = (string) ($benchmarkCase->input['prompt'] ?? '');

        if ($benchmarkCase->task === AiTask::Chat) {
            $benchmarkCase->loadMissing('attachments');
            $this->ensureAttachmentsStored($benchmarkCase);
        }

        $startedAt = hrtime(true);
        [$agentResponse, $output] = $this->prompt($benchmarkCase, $prompt);
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $fixtureBook = $this->benchmarkRunContext->fixtures();

        return new CaseOutcome(
            output: $output,
            intendedActions: $fixtureBook->intendedActions(),
            toolLog: $fixtureBook->log(),
            toolCalls: $fixtureBook->calls(),
            toolMisses: $fixtureBook->misses(),
            promptTokens: $agentResponse->usage->inputTokens,
            completionTokens: $agentResponse->usage->outputTokens,
            latencyMs: $latencyMs,
            invocationId: $agentResponse->invocationId,
        );
    }

    /**
     * Fails before any provider call when a case attachment's file is gone.
     */
    private function ensureAttachmentsStored(BenchmarkCase $benchmarkCase): void
    {
        foreach ($benchmarkCase->attachments as $attachment) {
            throw_unless(Storage::disk($attachment->disk)->exists($attachment->path), RuntimeException::class, sprintf(
                'The attachment %s (%s) is no longer stored; attach it to the case again.',
                $attachment->original_name,
                $attachment->turnLabel(),
            ));
        }
    }

    /**
     * The SDK files of one user turn (null is the final prompt).
     *
     * @return list<File>
     */
    private function sdkFiles(BenchmarkCase $benchmarkCase, ?int $turn): array
    {
        return $benchmarkCase->attachmentsFor($turn)
            ->map(static fn (BenchmarkCaseAttachment $attachment): File => $attachment->toSdkFile())
            ->all();
    }

    /**
     * @return array{0: AgentResponse, 1: string}
     */
    private function prompt(BenchmarkCase $benchmarkCase, string $prompt): array
    {
        return match ($benchmarkCase->task) {
            AiTask::Chat => $this->asText(resolve(MediaAgent::class)->prompt($prompt, attachments: $this->sdkFiles($benchmarkCase, null))),
            AiTask::Title => $this->asTitle(resolve(TitleAgent::class)->prompt($prompt)),
            AiTask::Decision => $this->asText(resolve(DecisionAgent::class)->prompt($prompt)),
            AiTask::FileInspector => $this->asJson(resolve(MediaFileInspectorAgent::class)->prompt($prompt)),
            AiTask::StuckDownloadInvestigator => $this->asJson(resolve(StuckDownloadInvestigatorAgent::class)->prompt($prompt)),
            AiTask::PriceUpdater => $this->asText($this->promptPriceUpdater($benchmarkCase, $prompt)),
            default => throw new InvalidArgumentException(sprintf('The %s task cannot be benchmarked.', $benchmarkCase->task->value)),
        };
    }

    /**
     * Scoped to the case providers as a dry run, and always on the app
     * WebFetchTool (whose calls the fixtures answer): the provider-native
     * WebFetch flag is forced off for the run and restored afterwards.
     */
    private function promptPriceUpdater(BenchmarkCase $benchmarkCase, string $prompt): AgentResponse
    {
        /** @var list<string> $providers */
        $providers = array_values(array_filter((array) ($benchmarkCase->input['providers'] ?? []), is_string(...)));
        $providerWebFetch = config(self::PROVIDER_WEBFETCH_FLAG);

        config()->set(self::PROVIDER_WEBFETCH_FLAG, false);

        try {
            return resolve(PriceFetcherAgent::class)
                ->forScope(RefreshScope::forProviders($providers), $providers, [], dryRun: true)
                ->prompt($prompt);
        } finally {
            config()->set(self::PROVIDER_WEBFETCH_FLAG, $providerWebFetch);
        }
    }

    /**
     * @return array{0: AgentResponse, 1: string}
     */
    private function asText(AgentResponse $agentResponse): array
    {
        return [$agentResponse, $agentResponse->text];
    }

    /**
     * @return array{0: AgentResponse, 1: string}
     */
    private function asTitle(AgentResponse $agentResponse): array
    {
        $title = $agentResponse instanceof StructuredAgentResponse ? ($agentResponse['title'] ?? '') : $agentResponse->text;

        return [$agentResponse, is_string($title) ? $title : ''];
    }

    /**
     * @return array{0: AgentResponse, 1: string}
     */
    private function asJson(AgentResponse $agentResponse): array
    {
        $output = $agentResponse instanceof StructuredAgentResponse
            ? (string) json_encode($agentResponse->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $agentResponse->text;

        return [$agentResponse, $output];
    }
}
