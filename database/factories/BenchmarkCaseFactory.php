<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiTask;
use App\Enums\BenchmarkScoringType;
use App\Models\BenchmarkCase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BenchmarkCase>
 */
class BenchmarkCaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task' => AiTask::Decision,
            'name' => Str::ucfirst(fake()->unique()->words(3, true)),
            'tags' => [],
            'notes' => null,
            'input' => ['prompt' => 'Download stuck at 99% for two days'],
            'fixtures' => [],
            'scoring' => ['type' => BenchmarkScoringType::Actions->value, 'expected' => []],
        ];
    }

    public function forTask(AiTask $task): static
    {
        return $this->state(fn (array $attributes): array => ['task' => $task]);
    }

    public function judged(?string $note = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'scoring' => ['type' => BenchmarkScoringType::Judge->value, 'rubric_note' => $note, 'pass_threshold' => 7],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    public function expectingActions(array $actions): static
    {
        return $this->state(fn (array $attributes): array => [
            'scoring' => ['type' => BenchmarkScoringType::Actions->value, 'expected' => $actions],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $fixtures
     */
    public function withFixtures(array $fixtures): static
    {
        return $this->state(fn (array $attributes): array => ['fixtures' => $fixtures]);
    }
}
