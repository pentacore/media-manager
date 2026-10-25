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
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use LogicException;
use Throwable;

/**
 * Runs one benchmark case's agent once, exactly as production would invoke
 * it, inside a BenchmarkRunContext the caller already started (which pins the
 * candidate and answers every tool call from the case fixtures). Provider
 * failures propagate to the caller.
 */
final readonly class BenchmarkCaseRunner
{
    /**
     * Seconds a scripted case may spend starting turns; leaves room for the
     * judge inside the 280 s job.
     */
    private const int SCRIPTED_BUDGET_SECONDS = 200;

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

        if ($benchmarkCase->isScripted()) {
            return $this->runScripted($benchmarkCase);
        }

        $startedAt = hrtime(true);
        [$agentResponse, $output] = $this->prompt($benchmarkCase, $prompt);
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        return $this->outcome(
            $output,
            $agentResponse->usage->inputTokens,
            $agentResponse->usage->outputTokens,
            $latencyMs,
            $agentResponse->invocationId,
            [],
        );
    }

    /**
     * The candidate answers every user turn in order, on its own earlier
     * replies (and each turn's attachments) as history. Tokens and latency are
     * summed; the tool log comes from the one shared fixture book.
     */
    private function runScripted(BenchmarkCase $benchmarkCase): CaseOutcome
    {
        $userTurns = $benchmarkCase->userTurns();
        $total = count($userTurns);
        $messages = [];
        $transcript = [];
        $promptTokens = 0;
        $completionTokens = 0;
        $latencyMs = 0;
        $output = '';
        $invocationId = null;
        $startedScriptAt = now();

        foreach ($userTurns as $position => $userTurn) {
            if ($position > 0 && $startedScriptAt->diffInSeconds(now(), true) >= self::SCRIPTED_BUDGET_SECONDS) {
                throw ScriptedTurnFailed::budgetUsed(
                    $position + 1,
                    $total,
                    self::SCRIPTED_BUDGET_SECONDS,
                    $this->outcome($output, $promptTokens, $completionTokens, $latencyMs, $invocationId, $transcript),
                );
            }

            $content = $userTurn['content'];
            $files = $this->sdkFiles($benchmarkCase, $userTurn['turn']);

            $transcript[] = [
                'role' => 'user',
                'content' => $content,
                'attachments' => $benchmarkCase->attachmentsFor($userTurn['turn'])->pluck('original_name')->all(),
            ];
            $this->benchmarkRunContext->setHistory($messages);

            $startedAt = hrtime(true);

            try {
                $agentResponse = resolve(MediaAgent::class)->prompt($content, attachments: $files);
            } catch (Throwable $throwable) {
                throw ScriptedTurnFailed::turnThrew(
                    $position + 1,
                    $total,
                    $this->outcome($output, $promptTokens, $completionTokens, $latencyMs, $invocationId, $transcript),
                    $throwable,
                );
            }

            $latencyMs += (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $promptTokens += $agentResponse->usage->inputTokens;
            $completionTokens += $agentResponse->usage->outputTokens;
            $output = $agentResponse->text;
            $invocationId = $agentResponse->invocationId;

            $transcript[] = ['role' => 'assistant', 'content' => $output, 'attachments' => []];
            $messages[] = new UserMessage($content, $files);
            $messages[] = new AssistantMessage($output);
        }

        return $this->outcome($output, $promptTokens, $completionTokens, $latencyMs, $invocationId, $transcript);
    }

    /**
     * @param  list<array{role: 'user'|'assistant', content: string, attachments: list<string>}>  $transcript
     */
    private function outcome(string $output, int $promptTokens, int $completionTokens, int $latencyMs, ?string $invocationId, array $transcript): CaseOutcome
    {
        $fixtureBook = $this->benchmarkRunContext->fixtures();

        return new CaseOutcome(
            output: $output,
            intendedActions: $fixtureBook->intendedActions(),
            toolLog: $fixtureBook->log(),
            toolCalls: $fixtureBook->calls(),
            toolMisses: $fixtureBook->misses(),
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            latencyMs: $latencyMs,
            invocationId: $invocationId,
            transcript: $transcript,
        );
    }

    /**
     * Fails before any provider call when a case attachment's file is gone.
     */
    private function ensureAttachmentsStored(BenchmarkCase $benchmarkCase): void
    {
        foreach ($benchmarkCase->attachments as $attachment) {
            throw_unless(Storage::disk($attachment->disk)->exists($attachment->path), CaseAttachmentMissing::class, sprintf(
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
