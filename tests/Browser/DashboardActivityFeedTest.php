<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\User;

test('a viewer dashboard lists only their own activity, without a log link', function (): void {
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

/**
 * Hands an ActivityLogCreated broadcast to the dashboard's listener on one
 * channel (named without Echo's `private-` prefix). Tests broadcast through
 * the null driver, so this emits on the pusher-js channel Echo subscribed
 * to; it throws when the page never subscribed to that channel.
 *
 * @param  array<string, mixed>  $row
 */
function dashboardActivityFeedEmitScript(string $channel, array $row): string
{
    $json = json_encode($row, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const channel = window.Pusher?.instances?.[0]?.channel('private-{$channel}');
            if (!channel) {
                throw new Error('The dashboard has not subscribed to {$channel}.');
            }
            channel.emit('ActivityLogCreated', {$json});
        })()
    JS;
}

/**
 * The channels the page listens on for ActivityLogCreated, sorted and
 * comma-joined (pusher-js keeps bound callbacks under `_<event>`).
 */
function dashboardActivityFeedChannelsScript(): string
{
    return <<<'JS'
        (() => {
            const channels = window.Pusher?.instances?.[0]?.channels?.channels ?? {};
            return Object.keys(channels)
                .filter((name) => (channels[name].callbacks?._callbacks?._ActivityLogCreated ?? []).length > 0)
                .sort()
                .join(',');
        })()
    JS;
}

/**
 * An ActivityLogCreated payload as the event broadcasts it.
 *
 * @return array<string, mixed>
 */
function dashboardActivityFeedRow(int $id, string $category, string $description): array
{
    return [
        'id' => $id, 'action' => 'example.action', 'category' => $category, 'description' => $description,
        'user_name' => 'Example User', 'service_id' => null, 'service_name' => null, 'service_type' => null,
        'subject_type' => null, 'subject_id' => null, 'metadata' => null,
        'created_at' => '2026-10-12T10:00:00.000000Z',
    ];
}

test('an admin dashboard shows audit rows live next to activity rows', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('dashboard', absolute: false))->assertNoSmoke();

    expect($webpage->script(dashboardActivityFeedChannelsScript()))->toBe('private-activity,private-activity.audit');

    $webpage->script(dashboardActivityFeedEmitScript('activity.audit', dashboardActivityFeedRow(980_001, 'audit', 'Updated the Example Sonarr connection.')));
    $webpage->script(confirmDialogWaitUntilScript("document.body.innerText.includes('Updated the Example Sonarr connection.')"));

    $webpage->assertSeeIn('[data-dashboard-activity-row]', 'Updated the Example Sonarr connection.')
        ->assertNoSmoke();
});

test("a viewer's dashboard listens only on their own channel and shows their new rows live", function (): void {
    $viewer = User::factory()->create();
    $this->actingAs($viewer);

    $webpage = visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertSee('No recent activity');

    expect($webpage->script(dashboardActivityFeedChannelsScript()))->toBe(sprintf('private-App.Models.User.%d', $viewer->id));

    $webpage->script(dashboardActivityFeedEmitScript(sprintf('App.Models.User.%d', $viewer->id), dashboardActivityFeedRow(980_002, 'activity', 'Requested Example Movie from Seerr.')));
    $webpage->script(confirmDialogWaitUntilScript("document.querySelector('[data-dashboard-activity-row]') !== null"));

    $webpage->assertCount('[data-dashboard-activity-row]', 1)
        ->assertSeeIn('[data-dashboard-activity-row]', 'Requested Example Movie from Seerr.')
        ->assertDontSee('No recent activity')
        ->assertNoSmoke();
});

test('a member dashboard listens on the activity feed only, not on audit rows', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('dashboard', absolute: false))->assertNoSmoke();

    expect($webpage->script(dashboardActivityFeedChannelsScript()))->toBe('private-activity');
});
