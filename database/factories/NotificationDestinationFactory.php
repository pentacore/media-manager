<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationSeverity;
use App\Enums\PushChannelType;
use App\Models\NotificationDestination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationDestination>
 */
class NotificationDestinationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => PushChannelType::Discord,
            'label' => 'Ops '.fake()->word(),
            'config' => ['url' => 'https://discord.com/api/webhooks/'.fake()->numberBetween(1, 999).'/'.fake()->sha1()],
            'is_enabled' => true,
            'min_severity' => NotificationSeverity::Info,
        ];
    }

    public function ntfy(string $topic = 'mm-global'): static
    {
        return $this->state(fn (): array => ['channel' => PushChannelType::Ntfy, 'config' => ['topic' => $topic]]);
    }

    public function discord(): static
    {
        return $this->state(fn (): array => ['channel' => PushChannelType::Discord]);
    }

    public function telegram(string $chatId = '-1001'): static
    {
        return $this->state(fn (): array => ['channel' => PushChannelType::Telegram, 'config' => ['chat_id' => $chatId]]);
    }

    public function webhook(?string $secret = 's3cret'): static
    {
        return $this->state(fn (): array => [
            'channel' => PushChannelType::Webhook,
            'config' => ['url' => 'https://hooks.example.com/mm', 'secret' => $secret],
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['is_enabled' => false]);
    }

    public function minSeverity(NotificationSeverity $notificationSeverity): static
    {
        return $this->state(fn (): array => ['min_severity' => $notificationSeverity]);
    }
}
