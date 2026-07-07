<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StatRollup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatRollup>
 */
class StatRollupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'metric' => 'webhooks.received',
            'period' => 'day',
            'bucket' => CarbonImmutable::now('UTC')->startOfDay(),
            'dimensions' => ['service' => 'sonarr'],
            'count' => fake()->numberBetween(1, 50),
            'sum' => null,
            'min' => null,
            'max' => null,
        ];
    }

    public function hour(): static
    {
        return $this->state(fn (): array => [
            'period' => 'hour',
            'bucket' => CarbonImmutable::now('UTC')->startOfHour(),
        ]);
    }
}
