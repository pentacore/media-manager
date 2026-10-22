<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BenchmarkNeutralRejudgeStatus;
use App\Models\BenchmarkNeutralJudgement;
use App\Models\BenchmarkNeutralRejudge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenchmarkNeutralRejudge>
 */
class BenchmarkNeutralRejudgeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $judge = ['provider' => 'openai', 'model' => 'gpt-neutral', 'reasoning' => null];

        return [
            'run_ids' => [],
            'judge' => $judge,
            'judge_key' => BenchmarkNeutralJudgement::keyFor($judge),
            'status' => BenchmarkNeutralRejudgeStatus::Completed,
            'estimate_usd' => '0.010000',
            'cost_cap_usd' => '1.000000',
            'cost_usd' => '0.000000',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BenchmarkNeutralRejudgeStatus::Running,
            'finished_at' => null,
        ]);
    }

    /**
     * @param  list<int>  $runIds
     * @param  array<string, mixed>  $judge
     */
    public function forRuns(array $runIds, array $judge): static
    {
        sort($runIds);

        return $this->state(fn (array $attributes): array => [
            'run_ids' => $runIds,
            'judge' => $judge,
            'judge_key' => BenchmarkNeutralJudgement::keyFor($judge),
        ]);
    }
}
