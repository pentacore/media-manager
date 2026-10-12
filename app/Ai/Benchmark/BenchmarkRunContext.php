<?php

declare(strict_types=1);

namespace App\Ai\Benchmark;

use App\Ai\ResolvedSelection;
use App\Enums\AiMode;
use App\Enums\AiTask;
use Laravel\Ai\Messages\Message;
use LogicException;

/**
 * One benchmark job's run, candidate and fixtures. Scoped, so a run never
 * leaks into the next Octane request or queued job; inactive unless start()
 * was called, and every consumer checks active() first.
 *
 * While active, TaskModelResolver pins the case task to the candidate (and
 * BenchmarkJudge to the judge), AiSettings drops the failover provider,
 * ReplayBenchmarkTools answers every tool call from the fixtures, MediaAgent
 * replays the case history and usage rows carry the run id.
 */
final class BenchmarkRunContext
{
    private ?int $runId = null;

    private ?AiTask $task = null;

    private ?ResolvedSelection $candidate = null;

    private ?ResolvedSelection $judge = null;

    private ?FixtureBook $fixtureBook = null;

    /** @var list<Message> */
    private array $history = [];

    private ?AiMode $mode = null;

    /**
     * @param  list<Message>  $history
     */
    public function start(int $runId, AiTask $aiTask, ResolvedSelection $candidate, ?ResolvedSelection $judge, FixtureBook $fixtureBook, array $history = [], ?AiMode $aiMode = null): void
    {
        $this->runId = $runId;
        $this->task = $aiTask;
        $this->candidate = $candidate;
        $this->judge = $judge;
        $this->fixtureBook = $fixtureBook;
        $this->history = $history;
        $this->mode = $aiMode;
    }

    public function stop(): void
    {
        $this->runId = null;
        $this->task = null;
        $this->candidate = null;
        $this->judge = null;
        $this->fixtureBook = null;
        $this->history = [];
        $this->mode = null;
    }

    public function active(): bool
    {
        return $this->runId !== null;
    }

    public function runId(): ?int
    {
        return $this->runId;
    }

    public function task(): ?AiTask
    {
        return $this->task;
    }

    /**
     * The candidate for the case task, the judge for BenchmarkJudge, null
     * otherwise (and always null while inactive).
     */
    public function selectionFor(AiTask $aiTask): ?ResolvedSelection
    {
        if (! $this->active()) {
            return null;
        }

        return match ($aiTask) {
            $this->task => $this->candidate,
            AiTask::BenchmarkJudge => $this->judge,
            default => null,
        };
    }

    public function fixtures(): FixtureBook
    {
        throw_unless($this->fixtureBook instanceof FixtureBook, LogicException::class, 'No benchmark run is active.');

        return $this->fixtureBook;
    }

    /**
     * @return list<Message>
     */
    public function history(): array
    {
        return $this->history;
    }

    public function mode(): ?AiMode
    {
        return $this->mode;
    }
}
