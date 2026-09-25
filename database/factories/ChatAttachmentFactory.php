<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChatAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;

/**
 * @extends Factory<ChatAttachment>
 */
class ChatAttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->admin(),
            'disk' => 'local',
            'path' => sprintf('chat-attachments/%s.png', fake()->uuid()),
            'original_name' => 'screenshot.png',
            'mime_type' => 'image/png',
            'size' => 1024,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ChatAttachment $chatAttachment): void {
            Storage::disk($chatAttachment->disk)->put($chatAttachment->path, 'png-bytes');
        });
    }
}
