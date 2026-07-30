<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\User;

test('the Action Queue badge renders the pending action count', function (): void {
    $member = User::factory()->member()->create();

    ActionRequest::factory()->count(3)->create([
        'status' => ActionRequestStatus::Pending,
    ]);

    $this->actingAs($member);

    visit('/dashboard')
        ->assertNoSmoke()
        ->assertSee('Action Queue')
        ->assertSee('3');
});

test('the Action Queue badge is absent when nothing is pending', function (): void {
    // Pins the other half of the badge contract: `3` is not incidental page
    // text, so the assertion above is genuinely reading the counter.
    $this->actingAs(User::factory()->member()->create());

    visit('/dashboard')
        ->assertNoSmoke()
        ->assertSee('Action Queue')
        ->assertDontSee('3');
});
