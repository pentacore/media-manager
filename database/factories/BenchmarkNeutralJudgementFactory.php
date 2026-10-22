<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BenchmarkJudgeStatus;
use App\Models\BenchmarkNeutralJudgement;
use App\Models\BenchmarkResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenchmarkNeutralJudgement>
 */
class BenchmarkNeutralJudgementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $judge = ['provider' => 'openai', 'model' => 'gpt-neutral', 'reasoning' => null];

        return [
            'benchmark_result_id' => BenchmarkResult::factory(),
            'benchmark_neutral_rejudge_id' => null,
            'judge_key' => BenchmarkNeutralJudgement::keyFor($judge),
            'judge' => $judge,
            'status' => BenchmarkJudgeStatus::Ok,
            'score' => 0.8,
            'passed' => true,
            'detail' => ['score_10' => 8, 'rationale' => 'Neutral verdict'],
            'cost_usd' => '0.000000',
        ];
    }

    /**
     * @param  array<string, mixed>  $judge
     */
    public function forResult(BenchmarkResult $benchmarkResult, array $judge): static
    {
        return $this->state(fn (array $attributes): array => [
            'benchmark_result_id' => $benchmarkResult->id,
            'judge' => $judge,
            'judge_key' => BenchmarkNeutralJudgement::keyFor($judge),
        ]);
    }

    public function queued(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BenchmarkJudgeStatus::Queued,
            'score' => null,
            'passed' => null,
            'detail' => null,
        ]);
    }

    public function scored(float $score, bool $passed): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BenchmarkJudgeStatus::Ok,
            'score' => $score,
            'passed' => $passed,
            'detail' => ['score_10' => (int) round($score * 10), 'rationale' => 'Neutral verdict'],
        ]);
    }

    public function failed(string $error): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BenchmarkJudgeStatus::Failed,
            'score' => null,
            'passed' => null,
            'detail' => ['error' => $error],
        ]);
    }
}
