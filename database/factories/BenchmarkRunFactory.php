<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BenchmarkRunStatus;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BenchmarkRun>
 */
class BenchmarkRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => Str::ucfirst(fake()->unique()->words(3, true)),
            'status' => BenchmarkRunStatus::Pending,
            'candidates' => [['provider' => 'openai', 'model' => 'gpt-5-mini']],
            'judge' => null,
            'case_ids' => [],
            'estimate_usd' => '0.000000',
            'cost_cap_usd' => null,
            'cost_usd' => '0.000000',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    public function withCandidates(array $candidates): static
    {
        return $this->state(fn (array $attributes): array => ['candidates' => $candidates]);
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BenchmarkRunStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BenchmarkRunStatus::Completed,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BenchmarkRunStatus::Cancelled,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ]);
    }

    public function stoppedCostCap(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BenchmarkRunStatus::StoppedCostCap,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ]);
    }

    public function forSchedule(BenchmarkSchedule $benchmarkSchedule): static
    {
        return $this->state(fn (array $attributes): array => [
            'benchmark_schedule_id' => $benchmarkSchedule->id,
            'judge' => $benchmarkSchedule->judge,
        ]);
    }
}
