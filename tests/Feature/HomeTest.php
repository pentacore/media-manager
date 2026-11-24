<?php

declare(strict_types=1);

use App\Models\User;

test('home sends a guest to the login page', function (): void {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('home sends a signed-in user to the dashboard', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});
