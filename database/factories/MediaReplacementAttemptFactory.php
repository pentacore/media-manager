<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MediaReplacementStatus;
use App\Models\ActionRequest;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
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
            'target' => ['service' => 'sonarr', 'episode_file_ids' => [501]],
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
}
