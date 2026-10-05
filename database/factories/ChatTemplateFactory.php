<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChatTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChatTemplate>
 */
class ChatTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->admin(),
            'name' => Str::ucfirst(fake()->unique()->words(3, true)),
            'body' => 'Check Sonarr and Radarr for stuck downloads',
            'variables' => [],
            'auto_send' => false,
            'pinned' => false,
            'last_used_at' => null,
        ];
    }

    public function pinned(): static
    {
        return $this->state(fn (array $attributes): array => ['pinned' => true]);
    }

    public function autoSend(): static
    {
        return $this->state(fn (array $attributes): array => ['auto_send' => true]);
    }

    public function lastUsedAt(CarbonImmutable $lastUsedAt): static
    {
        return $this->state(fn (array $attributes): array => ['last_used_at' => $lastUsedAt]);
    }

    /**
     * @param  list<array<string, mixed>>  $variables
     */
    public function withBody(string $body, array $variables): static
    {
        return $this->state(fn (array $attributes): array => ['body' => $body, 'variables' => $variables]);
    }

    public function subtitleCheck(): static
    {
        return $this->withBody('Check {{anime:title,year}} S{{season}}E{{episode}} for subtitles', [
            ['name' => 'anime', 'label' => 'Anime', 'type' => 'series', 'default' => null, 'options' => null],
            ['name' => 'season', 'label' => 'Season', 'type' => 'number', 'default' => null, 'options' => null],
            ['name' => 'episode', 'label' => 'Episode', 'type' => 'number', 'default' => null, 'options' => null],
        ]);
    }
}
