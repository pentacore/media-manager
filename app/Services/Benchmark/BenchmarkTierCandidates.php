<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Ai\TaskModelResolver;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;

/**
 * The models a task currently runs on, as benchmark candidates: its
 * configured tiers in order, plus its resolved model when a tier inherits
 * (`auto` or blank) or no tier is set.
 */
final readonly class BenchmarkTierCandidates
{
    public function __construct(private TaskModelResolver $taskModelResolver) {}

    /**
     * @return array<string, list<array{provider: string, model: string, reasoning: string}>>
     */
    public function all(): array
    {
        $tiers = [];

        foreach (AiTask::cases() as $aiTask) {
            if ($aiTask->isBenchmarkable()) {
                $tiers[$aiTask->value] = $this->forTask($aiTask);
            }
        }

        return $tiers;
    }

    /**
     * @return list<array{provider: string, model: string, reasoning: string}>
     */
    public function forTask(AiTask $aiTask): array
    {
        $selections = [];
        $inherits = false;

        foreach ($this->taskModelResolver->tiers($aiTask) as $aiTaskModel) {
            if (blank($aiTaskModel->model) || $aiTaskModel->model === 'auto') {
                $inherits = true;

                continue;
            }

            $selections[] = [
                'provider' => (string) ($aiTaskModel->provider ?: config('ai.default', 'openai')),
                'model' => (string) $aiTaskModel->model,
                'reasoning' => ($aiTaskModel->reasoning ?? AiReasoningLevel::ProviderDefault)->value,
            ];
        }

        if ($inherits || $selections === []) {
            $resolved = $this->taskModelResolver->resolve($aiTask);
            $selections[] = [
                'provider' => $resolved->provider,
                'model' => $resolved->model,
                'reasoning' => $resolved->reasoning->value,
            ];
        }

        return array_values(array_unique($selections, SORT_REGULAR));
    }
}
