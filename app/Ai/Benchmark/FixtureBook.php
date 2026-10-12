<?php

declare(strict_types=1);

namespace App\Ai\Benchmark;

final class FixtureBook
{
    public const string MISS_RESULT = '{"error":"not_recorded","message":"Not available in this recorded case. Try a different approach or answer with what you have."}';

    private int $calls = 0;

    private int $misses = 0;

    /** @var list<array{tool: string, arguments: array<string, mixed>}> */
    private array $intended = [];

    /** @var list<array{tool: string, arguments: array<string, mixed>, result: string|null, hit: bool}> */
    private array $log = [];

    /** @param list<array{tool: string, arguments: array<string, mixed>, result: string}> $fixtures */
    public function __construct(private readonly array $fixtures) {}

    /**
     * The recorded result for this call, or null on a miss (the call and miss are counted).
     *
     * @param  array<string, mixed>  $arguments
     */
    public function answer(string $tool, array $arguments): ?string
    {
        $result = $this->peek($tool, $arguments);

        $this->calls++;

        if ($result === null) {
            $this->misses++;
        }

        $this->log[] = ['tool' => $tool, 'arguments' => $arguments, 'result' => $result, 'hit' => $result !== null];

        return $result;
    }

    /**
     * Look up a recorded result without counting or logging the call.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function peek(string $tool, array $arguments): ?string
    {
        foreach ($this->fixtures as $fixture) {
            if ($fixture['tool'] === $tool && ToolArguments::same($fixture['arguments'], $arguments)) {
                return $fixture['result'];
            }
        }

        return null;
    }

    /**
     * Record a write tool's call as an intended action (each call counts).
     *
     * @param  array<string, mixed>  $arguments
     */
    public function intend(string $tool, array $arguments): void
    {
        $this->intended[] = ['tool' => $tool, 'arguments' => $arguments];
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function misses(): int
    {
        return $this->misses;
    }

    /** @return list<array{tool: string, arguments: array<string, mixed>}> */
    public function intendedActions(): array
    {
        return $this->intended;
    }

    /** @return list<array{tool: string, arguments: array<string, mixed>, result: string|null, hit: bool}> */
    public function log(): array
    {
        return $this->log;
    }
}
