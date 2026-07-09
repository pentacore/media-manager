<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FreePoolOverflowBehavior;
use App\Enums\FreeUsagePeriod;
use App\Models\AiFreeUsagePool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiFreeUsagePool>
 */
class AiFreeUsagePoolFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'pool-'.fake()->uuid(),
            'period' => FreeUsagePeriod::Monthly,
            'unified' => false,
            'free_input_tokens' => 1_000_000,
            'free_output_tokens' => 500_000,
            'free_total_tokens' => null,
            'overflow_behavior' => FreePoolOverflowBehavior::FitOrPaid,
            'documentation_url' => null,
        ];
    }

    public function overflow(FreePoolOverflowBehavior $freePoolOverflowBehavior): static
    {
        return $this->state(fn (): array => ['overflow_behavior' => $freePoolOverflowBehavior]);
    }

    public function unified(int $totalTokens = 1_000_000): static
    {
        return $this->state(fn (): array => [
            'unified' => true,
            'free_input_tokens' => null,
            'free_output_tokens' => null,
            'free_total_tokens' => $totalTokens,
        ]);
    }

    public function period(FreeUsagePeriod $freeUsagePeriod): static
    {
        return $this->state(fn (): array => ['period' => $freeUsagePeriod]);
    }
}
