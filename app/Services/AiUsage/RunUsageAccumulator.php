<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Sums each in-flight agent run's completed-step usage so a run that dies
 * mid-way still bills the steps it paid for (RecordFailedAgentRun) and the
 * per-step budget middleware can price the run so far.
 */
final class RunUsageAccumulator
{
    /** @var array<string, array{provider: string, model: string, usage: TextUsage}> */
    private array $runs = [];

    public function add(string $invocationId, string $provider, string $model, TextUsage $textUsage): void
    {
        $existing = $this->runs[$invocationId]['usage'] ?? null;

        $this->runs[$invocationId] = [
            'provider' => $provider,
            'model' => $model,
            'usage' => $existing instanceof TextUsage ? $existing->add($textUsage) : $textUsage,
        ];
    }

    public function usage(string $invocationId): ?TextUsage
    {
        return $this->runs[$invocationId]['usage'] ?? null;
    }

    public function provider(string $invocationId): ?string
    {
        return $this->runs[$invocationId]['provider'] ?? null;
    }

    public function model(string $invocationId): ?string
    {
        return $this->runs[$invocationId]['model'] ?? null;
    }

    public function forget(string $invocationId): void
    {
        unset($this->runs[$invocationId]);
    }
}
