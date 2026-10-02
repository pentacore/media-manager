<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\User;

test('a viewer dashboard lists only their own activity, without the live dot or a log link', function (): void {
    $viewer = User::factory()->create();
    ActivityLog::factory()->create(['user_id' => $viewer->id, 'description' => 'Requested Dune from Seerr.']);
    ActivityLog::factory()->create(['description' => 'Paused the SABnzbd queue.']);

    $this->actingAs($viewer);

    visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-dashboard-activity-heading]', 'Your activity')
        ->assertCount('[data-dashboard-activity-row]', 1)
        ->assertSeeIn('[data-dashboard-activity-row]', 'Requested Dune from Seerr.')
        ->assertDontSee('Paused the SABnzbd queue.')
        ->assertMissing('[data-dashboard-activity-log-link]');
});

test('a viewer with no activity of their own sees the empty feed', function (): void {
    ActivityLog::factory()->create(['description' => 'Paused the SABnzbd queue.']);

    $this->actingAs(User::factory()->create());

    visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertCount('[data-dashboard-activity-row]', 0)
        ->assertSee('No recent activity')
        ->assertDontSee('Paused the SABnzbd queue.');
});

test("a member dashboard keeps the live feed of everyone's activity and the log link", function (): void {
    $member = User::factory()->member()->create();
    ActivityLog::factory()->create(['user_id' => $member->id, 'description' => 'Approved a request.']);
    ActivityLog::factory()->create(['description' => 'Paused the SABnzbd queue.']);

    $this->actingAs($member);

    visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-dashboard-activity-heading]', 'Live activity')
        ->assertCount('[data-dashboard-activity-row]', 2)
        ->assertVisible('[data-dashboard-activity-log-link]');
});
