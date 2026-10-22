<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Models\BenchmarkResult;

/**
 * What one benchmark case run produced: the candidate's output (text, or the
 * JSON of a structured response), the write calls it intended, every tool
 * call it made in order, and its usage and latency.
 */
final readonly class CaseOutcome
{
    /**
     * @param  list<array{tool: string, arguments: array<string, mixed>}>  $intendedActions
     * @param  list<array{tool: string, arguments: array<string, mixed>, result: string|null, hit: bool, write?: true, refused?: true}>  $toolLog
     */
    public function __construct(
        public string $output,
        public array $intendedActions,
        public array $toolLog,
        public int $toolCalls,
        public int $toolMisses,
        public int $promptTokens,
        public int $completionTokens,
        public int $latencyMs,
        public ?string $invocationId,
    ) {}

    /**
     * The outcome as stored on a result, normalised so a malformed stored
     * entry cannot break the judge prompt.
     */
    public static function fromResult(BenchmarkResult $benchmarkResult): self
    {
        $storedLog = $benchmarkResult->score_detail['tool_log'] ?? [];
        $toolLog = [];

        foreach (is_array($storedLog) ? $storedLog : [] as $call) {
            if (! is_array($call) || ! is_string($call['tool'] ?? null)) {
                continue;
            }

            $toolLog[] = [
                'tool' => $call['tool'],
                'arguments' => is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
                'result' => is_string($call['result'] ?? null) ? $call['result'] : null,
                'hit' => (bool) ($call['hit'] ?? false),
                ...(($call['write'] ?? false) === true ? ['write' => true] : []),
                ...(($call['refused'] ?? false) === true ? ['refused' => true] : []),
            ];
        }

        $intendedActions = [];

        foreach ($benchmarkResult->intended_actions as $action) {
            if (! is_string($action['tool'] ?? null)) {
                continue;
            }

            $intendedActions[] = ['tool' => $action['tool'], 'arguments' => is_array($action['arguments'] ?? null) ? $action['arguments'] : []];
        }

        return new self(
            output: $benchmarkResult->output ?? '',
            intendedActions: $intendedActions,
            toolLog: $toolLog,
            toolCalls: $benchmarkResult->tool_calls,
            toolMisses: $benchmarkResult->tool_misses,
            promptTokens: $benchmarkResult->prompt_tokens,
            completionTokens: $benchmarkResult->completion_tokens,
            latencyMs: $benchmarkResult->latency_ms ?? 0,
            invocationId: null,
        );
    }

    /**
     * @return array{output: string, intended_actions: list<array{tool: string, arguments: array<string, mixed>}>, tool_log: list<array{tool: string, arguments: array<string, mixed>, result: string|null, hit: bool, write?: true, refused?: true}>, tool_calls: int, tool_misses: int, prompt_tokens: int, completion_tokens: int, latency_ms: int, invocation_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'output' => $this->output,
            'intended_actions' => $this->intendedActions,
            'tool_log' => $this->toolLog,
            'tool_calls' => $this->toolCalls,
            'tool_misses' => $this->toolMisses,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'latency_ms' => $this->latencyMs,
            'invocation_id' => $this->invocationId,
        ];
    }
}
