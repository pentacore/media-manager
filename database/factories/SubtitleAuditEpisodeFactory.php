<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubtitleAuditEpisodeOutcome;
use App\Models\SubtitleAuditBatch;
use App\Models\SubtitleAuditEpisode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubtitleAuditEpisode>
 */
class SubtitleAuditEpisodeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'subtitle_audit_batch_id' => SubtitleAuditBatch::factory(),
            'episode_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'season_number' => fake()->numberBetween(1, 5),
            'episode_number' => fake()->numberBetween(1, 24),
            'missing_languages' => ['eng'],
            'outcome' => SubtitleAuditEpisodeOutcome::Searching,
            'action_request_id' => null,
            'release_title' => null,
            'failure_reason' => null,
        ];
    }

    public function queued(): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => SubtitleAuditEpisodeOutcome::Queued,
            'release_title' => fake()->words(4, true),
        ]);
    }

    public function manualPick(): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => SubtitleAuditEpisodeOutcome::ManualPick,
        ]);
    }

    public function nothingFound(): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => SubtitleAuditEpisodeOutcome::NothingFound,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => SubtitleAuditEpisodeOutcome::Failed,
            'failure_reason' => 'search_failed',
        ]);
    }
}
