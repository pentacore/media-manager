<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Models\ClassificationOutcome;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClassificationOutcome> */
class ClassificationOutcomeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gate' => ClassificationGate::DecisionGate,
            'subject_key' => ClassificationOutcome::subjectKey('decision', fake()->unique()->numberBetween(1, 999999)),
            'question' => 'decision',
            'predicted' => null,
            'probability' => fake()->randomFloat(4, 0, 1),
            'threshold' => 0.3,
            'verdict' => ClassificationVerdict::Passed,
        ];
    }

    public function gate(ClassificationGate $classificationGate): static
    {
        return $this->state(fn (array $attributes): array => ['gate' => $classificationGate]);
    }

    public function resolved(bool $positive = true): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome_positive' => $positive,
            'outcome_detail' => $positive ? 'positive' : 'negative',
            'outcome_at' => now(),
        ]);
    }
}
