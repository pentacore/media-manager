<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiTask;
use App\Enums\BenchmarkScheduleFrequency;
use App\Models\BenchmarkSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BenchmarkSchedule>
 */
class BenchmarkScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => Str::ucfirst(fake()->unique()->words(3, true)),
            'task' => AiTask::Decision,
            'case_tags' => [],
            'judge' => ['provider' => 'openai', 'model' => 'gpt-judge', 'reasoning' => 'provider_default'],
            'cost_cap_usd' => '1.000000',
            'frequency' => BenchmarkScheduleFrequency::Daily,
            'time_of_day' => '03:00',
            'weekday' => null,
            'day_of_month' => null,
            'pass_rate_threshold' => '10.00',
            'score_threshold' => '10.00',
            'enabled' => true,
            'next_run_at' => now()->addDay(),
            'created_by' => User::factory()->admin(),
        ];
    }

    public function forTask(AiTask $aiTask): static
    {
        return $this->state(fn (array $attributes): array => ['task' => $aiTask]);
    }

    /**
     * @param  list<string>  $tags
     */
    public function withTags(array $tags): static
    {
        return $this->state(fn (array $attributes): array => ['case_tags' => $tags]);
    }

    public function weekly(int $weekday): static
    {
        return $this->state(fn (array $attributes): array => ['frequency' => BenchmarkScheduleFrequency::Weekly, 'weekday' => $weekday]);
    }

    public function monthly(int $day): static
    {
        return $this->state(fn (array $attributes): array => ['frequency' => BenchmarkScheduleFrequency::Monthly, 'day_of_month' => $day]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => ['enabled' => false, 'next_run_at' => null]);
    }

    public function due(): static
    {
        return $this->state(fn (array $attributes): array => ['next_run_at' => now()->subMinute()]);
    }
}
