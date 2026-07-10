<?php

declare(strict_types=1);

use App\Models\EmbyUserLink;
use App\Models\User;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
});

test('profile page exposes all of the users emby links', function (): void {
    $user = User::factory()->create();
    EmbyUserLink::factory()->count(2)->create(['user_id' => $user->id]);
    EmbyUserLink::factory()->create(); // another user's link — must not appear

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Profile')
            ->has('embyLinks', 2)
            ->has('embyLinks.0.id')
            ->has('embyLinks.0.emby_username')
        );
});
