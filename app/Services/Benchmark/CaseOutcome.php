<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

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
