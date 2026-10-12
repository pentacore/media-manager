<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use App\Ai\Benchmark\BenchmarkRunContext;
use App\Ai\Benchmark\ReplayTool;
use App\Ai\Benchmark\WriteToolDetector;
use Closure;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Tools\AgentTool;

/**
 * Inside a benchmark run, swap every tool of the step for a ReplayTool that
 * answers from the case fixtures, so no app tool, sub-agent or provider tool
 * runs. Provider tools are dropped and a ToolSearch is flattened into its
 * nested tools. Outside a run the step passes through untouched.
 */
final class ReplayBenchmarkTools
{
    public function handle(PendingStep $pendingStep, Closure $next): mixed
    {
        $benchmarkRunContext = resolve(BenchmarkRunContext::class);

        if (! $benchmarkRunContext->active()) {
            return $next($pendingStep);
        }

        return $next($pendingStep->withTools($this->replacements($pendingStep->tools, $benchmarkRunContext)));
    }

    /**
     * @param  array<int, mixed>  $tools
     * @return list<ReplayTool>
     */
    private function replacements(array $tools, BenchmarkRunContext $benchmarkRunContext): array
    {
        $replacements = [];

        foreach ($tools as $tool) {
            if ($tool instanceof ToolSearch) {
                array_push($replacements, ...$this->replacements($tool->tools, $benchmarkRunContext));

                continue;
            }

            if ($tool instanceof ProviderTool) {
                continue;
            }

            if ($tool instanceof Agent) {
                $tool = new AgentTool($tool);
            }

            if (! $tool instanceof Tool) {
                continue;
            }

            $replacements[] = $tool instanceof ReplayTool ? $tool : new ReplayTool(
                $tool,
                WriteToolDetector::writes($tool),
                $benchmarkRunContext->fixtures(),
                $benchmarkRunContext->mode(),
            );
        }

        return $replacements;
    }
}
