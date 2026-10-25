<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

use App\Ai\Middleware\AnswerOnFinalStep;
use App\Ai\Middleware\EnforceBudgetEachStep;
use App\Ai\Middleware\ReplayBenchmarkTools;
use App\Ai\Middleware\StopWhenClientDisconnected;

/**
 * Shared behaviour of a read-only HasStructuredOutput sub-agent that a
 * MediaAgent turn delegates to through AgentTool. Since laravel/ai 1.2.2
 * AgentTool prompts a structured sub-agent even inside a streamed turn.
 */
trait ActsAsStructuredSubAgent
{
    /**
     * Wrap every generation step: refuse a step once the hard budget is
     * crossed mid-run, force a plain answer on the final allowed step, stop
     * at the next step once the chat client disconnected, and answer tools
     * from fixtures inside a benchmark run.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new AnswerOnFinalStep, new EnforceBudgetEachStep, new StopWhenClientDisconnected, new ReplayBenchmarkTools];
    }
}
