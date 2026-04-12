<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmbyUserLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmbyUserLink>
 */
class EmbyUserLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'emby_user_id' => fake()->uuid(),
            'emby_username' => fake()->userName(),
        ];
    }
}
