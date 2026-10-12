<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BenchmarkResultStatus;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BenchmarkResult>
 */
class BenchmarkResultFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'benchmark_run_id' => BenchmarkRun::factory(),
            'benchmark_case_id' => BenchmarkCase::factory(),
            'case_name' => 'Benchmark case',
            'case_task' => 'decision',
            'candidate_index' => 0,
            'status' => BenchmarkResultStatus::Pending,
            'intended_actions' => [],
            'judge_status' => 'none',
        ];
    }

    public function forCase(BenchmarkRun $run, BenchmarkCase $case, int $candidateIndex): static
    {
        return $this->state(fn (array $attributes): array => [
            'benchmark_run_id' => $run->id,
            'benchmark_case_id' => $case->id,
            'case_name' => $case->name,
            'case_task' => $case->task->value,
            'candidate_index' => $candidateIndex,
        ]);
    }

    public function scored(float $score, bool $passed): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BenchmarkResultStatus::Ok,
            'score' => $score,
            'passed' => $passed,
        ]);
    }

    public function failed(string $error): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BenchmarkResultStatus::Failed,
            'error' => $error,
        ]);
    }
}
