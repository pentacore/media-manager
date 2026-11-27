<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\EmbyActivity;
use App\Models\User;

/*
 * The pages that patch rows in place from broadcasts (not live props) re-read
 * their data once when a dropped Reverb connection comes back. There is no
 * Reverb broker in the test env, so the pusher-js connection state is driven
 * by emitting `state_change` on it, as in LiveTransportTest.
 */

/**
 * Connected (as on page load), then dropped and restored: one reconnect.
 */
function realtimeReconnectScript(): string
{
    return <<<'JS'
        (() => {
            const connection = window.Pusher.instances[0].connection;
            connection.emit('state_change', { previous: 'connecting', current: 'connected' });
            connection.emit('state_change', { previous: 'connected', current: 'unavailable' });
            connection.emit('state_change', { previous: 'unavailable', current: 'connected' });
        })()
    JS;
}

/**
 * The recorded partial reloads whose `only` names $prop.
 *
 * @param  array<int, string>  $reloads
 * @return array<int, string>
 */
function realtimeReconnectReloadsOf(array $reloads, string $prop): array
{
    return array_values(array_filter(
        $reloads,
        fn (string $only): bool => in_array($prop, explode(',', $only), true),
    ));
}

test('the action queue reloads its requests after a reconnect', function (): void {
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Completed, 'title' => 'Example older request']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(reloadRecorderScript());

    // Created while the connection was down: no broadcast reaches the page.
    $missed = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Example missed request']);

    $webpage->script(realtimeReconnectScript());
    $webpage->script(confirmDialogWaitUntilScript(sprintf("document.querySelector('[data-action-row=\"%d\"]') !== null", $missed->id)));
    $webpage->wait(1.5);

    $reloads = $webpage->script('window.__mmReloads');
    $queueReloads = realtimeReconnectReloadsOf($reloads, 'requests');

    expect($queueReloads)->toHaveCount(1)
        ->and(explode(',', $queueReloads[0]))->toContain('requests', 'statusCounts');
    $webpage->assertSeeIn(sprintf('[data-action-row="%d"]', $missed->id), 'Example missed request')
        ->assertMissing('[data-action-stale]')
        ->assertNoSmoke();
});

test('the dashboard reloads its stats and activity after a reconnect', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('dashboard', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(reloadRecorderScript());

    ActivityLog::factory()->create(['description' => 'Example missed activity.']);

    $webpage->script(realtimeReconnectScript());
    $webpage->script(confirmDialogWaitUntilScript("document.querySelector('[data-dashboard-activity-row]') !== null"));
    $webpage->wait(1.5);

    $reloads = $webpage->script('window.__mmReloads');

    expect(realtimeReconnectReloadsOf($reloads, 'stats'))->toBe(['stats'])
        ->and(realtimeReconnectReloadsOf($reloads, 'recentActivity'))->toBe(['recentActivity'])
        ->and(realtimeReconnectReloadsOf($reloads, 'nav'))->toBe(['nav']);
    $webpage->assertSeeIn('[data-dashboard-activity-row]', 'Example missed activity.')
        ->assertNoSmoke();
});

test('a paused watch history waits for resume before reloading after a reconnect', function (): void {
    EmbyActivity::factory()->count(26)->create();
    $this->actingAs(User::factory()->admin()->create());

    // A later page pauses the live list. (A filter change is a full visit
    // that remounts the page with fresh rows, so it never resumes this list.)
    $webpage = visit(route('monitoring.watch-history', ['page' => 2], absolute: false))
        ->assertNoSmoke()
        ->assertCount('[data-watch-row]', 1);
    $webpage->script(appHydratedScript());
    $webpage->script(reloadRecorderScript());

    $webpage->script(realtimeReconnectScript());
    $webpage->wait(1.5);

    expect(realtimeReconnectReloadsOf($webpage->script('window.__mmReloads'), 'activities'))->toBe([]);
    $webpage->assertMissing('[data-watch-stale]');

    // Back to the first page (a preserveState visit) resumes the list, which
    // catches up once.
    $webpage->click('[data-watch-page="1"]');
    $webpage->script(confirmDialogWaitUntilScript("(window.__mmReloads ?? []).includes('activities')"));
    $webpage->wait(1.5);

    expect(realtimeReconnectReloadsOf($webpage->script('window.__mmReloads'), 'activities'))->toBe(['activities']);
    $webpage->assertCount('[data-watch-row]', 25)
        ->assertMissing('[data-watch-stale]')
        ->assertNoSmoke();
});

test('the nav counts reload after a reconnect', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('profile.edit', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(reloadRecorderScript());

    // Requested while the connection was down: the badge never heard of it.
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);

    $webpage->script(realtimeReconnectScript());
    $webpage->script(confirmDialogWaitUntilScript("document.querySelector('[data-nav-badge=\"Action Queue\"]')?.innerText.trim() === '1'"));
    $webpage->wait(1.5);

    $reloads = $webpage->script('window.__mmReloads');

    expect($reloads)->toHaveCount(1)
        ->and(explode(',', (string) $reloads[0]))->toContain('nav');
    $webpage->assertSeeIn('[data-nav-badge="Action Queue"]', '1')
        ->assertNoSmoke();
});

test('the AI prices reload their rows and refresh state after a reconnect', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.ai-prices.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(reloadRecorderScript());

    $webpage->script(realtimeReconnectScript());
    $webpage->script(confirmDialogWaitUntilScript("(window.__mmReloads ?? []).some((only) => only.split(',').includes('prices'))"));
    $webpage->wait(1.5);

    $priceReloads = realtimeReconnectReloadsOf($webpage->script('window.__mmReloads'), 'prices');

    expect($priceReloads)->toHaveCount(1)
        ->and(explode(',', $priceReloads[0]))->toContain('prices', 'refresh_running');
    $webpage->assertNoSmoke();
});
