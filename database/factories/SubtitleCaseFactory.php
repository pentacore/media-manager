<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubtitleCaseStatus;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubtitleCase>
 */
class SubtitleCaseFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'bazarr_connection_id' => ServiceConnection::factory()->bazarr(),
            'service_connection_id' => ServiceConnection::factory()->sonarr(),
            'download_action_request_id' => null,
            'replacement_action_request_id' => null,
            'media_type' => 'episode',
            'scope' => 'anime',
            'target_ids' => [
                'series_id' => fake()->numberBetween(1, 10_000),
                'episode_id' => fake()->numberBetween(1, 100_000),
                'episode_file_id' => fake()->numberBetween(1, 100_000),
            ],
            'file_fingerprint' => hash('sha256', fake()->uuid()),
            'required_languages' => [
                ['code' => 'eng', 'forced' => false, 'hearing_impaired' => false],
            ],
            'requirements_fingerprint' => hash('sha256', fake()->uuid()),
            'status' => SubtitleCaseStatus::Observing,
            'evidence' => ['missing_languages' => ['eng']],
            'failure_reason' => null,
            'grace_until' => now()->addDay(),
            'observed_at' => now(),
            'resolved_at' => null,
            'superseded_at' => null,
        ];
    }
}
