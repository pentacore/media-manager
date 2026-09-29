<?php

declare(strict_types=1);

use App\Models\ActionRequest;
use App\Models\User;

test('the dashboard lists pending approvals by title with their description', function (): void {
    $this->actingAs(User::factory()->member()->create());
    ActionRequest::factory()->described()->create();

    visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-pending-approval-title]', 'Delete series "Severance (2022)"')
        ->assertSeeIn('[data-pending-approval-description]', 'Sonarr will delete the series');
});

test('a viewer dashboard has no review queue link, approvals panel or webhook links', function (): void {
    $this->actingAs(User::factory()->create());
    ActionRequest::factory()->described()->create();

    visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertMissing('[data-dashboard-review-queue]')
        ->assertMissing('[data-dashboard-pending-approvals]')
        ->assertMissing('a[href^="/actions"]')
        ->assertMissing('a[href*="webhook"]')
        ->assertDontSee('Delete series "Severance (2022)"');
});

test('a member dashboard links to the review queue from the header and each approval', function (): void {
    $this->actingAs(User::factory()->member()->create());
    ActionRequest::factory()->described()->create();

    visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertPresent('[data-dashboard-review-queue]')
        ->assertPresent('[data-dashboard-pending-approvals] a[href^="/actions/requests"]');
});
