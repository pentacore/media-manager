<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SubtitleCase;
use App\Models\SubtitleUpload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubtitleUpload>
 */
class SubtitleUploadFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $uuid = fake()->uuid();

        return [
            'user_id' => User::factory(),
            'subtitle_case_id' => SubtitleCase::factory(),
            'action_request_id' => null,
            'path' => 'bazarr-subtitle-uploads/'.$uuid.'.srt',
            'display_name' => fake()->word().'.srt',
            'checksum' => hash('sha256', $uuid),
            'mime_type' => 'application/x-subrip',
            'format' => 'srt',
            'size_bytes' => 1024,
            'expires_at' => now()->addHour(),
            'consumed_at' => null,
            'cancelled_at' => null,
            'cleaned_up_at' => null,
        ];
    }
}
