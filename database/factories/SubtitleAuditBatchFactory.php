<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubtitleAuditBatchStatus;
use App\Models\ServiceConnection;
use App\Models\SubtitleAuditBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubtitleAuditBatch>
 */
class SubtitleAuditBatchFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'service_connection_id' => ServiceConnection::factory()->sonarr(),
            'series_id' => fake()->numberBetween(1, 10_000),
            'series_title' => fake()->words(3, true),
            'season_number' => null,
            'status' => SubtitleAuditBatchStatus::Searching,
            'search_deadline_at' => now()->addMinutes(30),
            'handed_off_at' => null,
            'completed_at' => null,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SubtitleAuditBatchStatus::Ready,
            'completed_at' => now(),
        ]);
    }

    public function handedOff(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SubtitleAuditBatchStatus::Ready,
            'completed_at' => now(),
            'handed_off_at' => now(),
        ]);
    }
}
