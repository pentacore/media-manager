<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MediaReplacementStatus;
use App\Models\ActionRequest;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaReplacementAttempt>
 */
class MediaReplacementAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action_request_id' => ActionRequest::factory()->state(['type' => 'replace_media_file']),
            'service_connection_id' => ServiceConnection::factory()->sonarr(),
            'status' => MediaReplacementStatus::Requested,
            'scope' => 'anime',
            'target' => [
                'service' => 'sonarr',
                'scope' => 'anime',
                'series_id' => 42,
                'display_name' => 'Trusted Anime S01E01',
                'season_number' => 1,
                'episode_numbers' => [1],
                'episode_ids' => [101],
                'episode_file_ids' => [501],
                'installed_release' => 'OLD',
            ],
            'candidate_fingerprint' => hash('sha256', fake()->uuid()),
            'candidate' => ['title' => 'Show S01E01 CR', 'confidence' => 98],
            'required_languages' => ['eng'],
            'download_id' => null,
            'grab_accepted_at' => null,
            'was_monitored' => null,
            'verification' => null,
            'failure_reason' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function downloading(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MediaReplacementStatus::Downloading,
            'started_at' => now()->subMinutes(5),
            'grab_attempted_at' => now()->subMinutes(5),
            'grab_accepted_at' => now()->subMinutes(4),
            'download_id' => 'ABC123',
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MediaReplacementStatus::Verified,
            'started_at' => now()->subHour(),
            'completed_at' => now()->subMinutes(10),
            'download_id' => 'ABC123',
            'verification' => ['subtitles_checked' => true, 'required' => ['eng'], 'found' => ['eng'], 'missing' => [], 'subtitles_ok' => true],
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MediaReplacementStatus::Failed,
            'started_at' => now()->subHour(),
            'completed_at' => now()->subMinutes(30),
            'failure_reason' => 'grab_rejected',
        ]);
    }

    public function needsAttention(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MediaReplacementStatus::NeedsAttention,
            'started_at' => now()->subHours(9),
            'completed_at' => now()->subMinutes(5),
            'failure_reason' => 'download_timeout',
        ]);
    }

    public function acknowledged(?User $user = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'acknowledged_at' => now(),
            'acknowledged_by' => $user?->id ?? User::factory()->admin(),
        ]);
    }

    public function monitoringSuspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'was_monitored' => true,
            'monitoring_suspended' => true,
        ]);
    }

    public function radarr(): static
    {
        return $this->state(fn (array $attributes): array => [
            'service_connection_id' => ServiceConnection::factory()->radarr(),
            'scope' => 'movie',
            'target' => ['service' => 'radarr', 'scope' => 'movie', 'movie_id' => 10, 'display_name' => 'A Movie', 'movie_file_ids' => [5]],
        ]);
    }
}
