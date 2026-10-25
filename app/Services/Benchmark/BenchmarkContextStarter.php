<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Ai\Benchmark\BenchmarkRunContext;
use App\Ai\Benchmark\FixtureBook;
use App\Ai\ResolvedSelection;
use App\Enums\AiMode;
use App\Enums\AiReasoningLevel;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkCaseAttachment;
use App\Models\BenchmarkRun;
use Laravel\Ai\Files\File;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use RuntimeException;

/**
 * Starts the benchmark context for one result: its run, the case task pinned
 * to the result's candidate, the judge (the run's, or a neutral one), the
 * case's fixtures, history and chat mode.
 */
final readonly class BenchmarkContextStarter
{
    /**
     * @param  array<string, mixed>|null  $judgeOverride  a `{provider, model, reasoning}` judge used instead of the run's
     */
    public function start(BenchmarkRunContext $benchmarkRunContext, BenchmarkRun $benchmarkRun, BenchmarkCase $benchmarkCase, int $candidateIndex, ?array $judgeOverride = null, bool $billsRun = true): void
    {
        $candidate = $benchmarkRun->candidates[$candidateIndex] ?? null;

        throw_unless(is_array($candidate), RuntimeException::class, sprintf('The run has no candidate #%d.', $candidateIndex));

        $judge = $judgeOverride ?? $benchmarkRun->judge;

        $benchmarkRunContext->start(
            $benchmarkRun->id,
            $benchmarkCase->task,
            self::selection($candidate),
            is_array($judge) ? self::selection($judge) : null,
            new FixtureBook($benchmarkCase->fixtures),
            $this->history($benchmarkCase),
            AiMode::tryFrom((string) ($benchmarkCase->input['mode'] ?? '')),
            $billsRun,
        );
    }

    /**
     * @param  array<string, mixed>  $selection  a stored `{provider, model, reasoning}`
     */
    public static function selection(array $selection): ResolvedSelection
    {
        return new ResolvedSelection(
            is_string($selection['provider'] ?? null) ? $selection['provider'] : '',
            is_string($selection['model'] ?? null) ? $selection['model'] : '',
            AiReasoningLevel::tryFrom(is_string($selection['reasoning'] ?? null) ? $selection['reasoning'] : '') ?? AiReasoningLevel::ProviderDefault,
        );
    }

    /**
     * The case's chat history as SDK messages (other roles are skipped).
     *
     * @return list<Message>
     */
    private function history(BenchmarkCase $benchmarkCase): array
    {
        $messages = [];

        foreach ((array) ($benchmarkCase->input['history'] ?? []) as $index => $turn) {
            if (! is_array($turn) || ! is_string($turn['content'] ?? null)) {
                continue;
            }

            $message = match ($turn['role'] ?? null) {
                'user' => new UserMessage(
                    $turn['content'],
                    is_int($index)
                        ? $benchmarkCase->attachmentsFor($index)->map(static fn (BenchmarkCaseAttachment $benchmarkCaseAttachment): File => $benchmarkCaseAttachment->toSdkFile())->all()
                        : [],
                ),
                'assistant' => new AssistantMessage($turn['content']),
                default => null,
            };

            if ($message instanceof Message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }
}
