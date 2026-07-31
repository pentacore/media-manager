<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubtitleCaseAttempt>
 */
class SubtitleCaseAttemptFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'subtitle_case_id' => SubtitleCase::factory(),
            'action_request_id' => null,
            'type' => SubtitleCaseAttemptType::Probe,
            'candidate_count' => 0,
            'eligible_candidate_count' => 0,
            'summary' => ['classified' => 0],
            'outcome' => SubtitleCaseAttemptOutcome::Started,
            'error_category' => null,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }
}
