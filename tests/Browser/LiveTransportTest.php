<?php

declare(strict_types=1);

use App\Models\MediaReplacementAttempt;
use App\Models\User;

/*
 * The client side of live props (resources/js/lib/liveTransport.ts) and the
 * reconnect signal in resources/js/composables/useWebSocket.ts. There is no
 * Reverb broker in the test env, so the pusher-js connection state is driven
 * by emitting `state_change` on it, and broadcasts by echoEmitScript().
 */

/**
 * Emits a pusher-js connection state change, as a dropped or restored Reverb
 * connection would.
 */
function liveTransportStateChangeScript(string $previous, string $current): string
{
    return sprintf(
        "window.Pusher.instances[0].connection.emit('state_change', { previous: '%s', current: '%s' })",
        $previous,
        $current,
    );
}

function liveTransportAttemptListenersScript(): string
{
    return "(window.Pusher?.instances?.[0]?.channel('private-admin.media-replacement')?.callbacks?.get('MediaReplacementAttemptChanged') ?? []).length";
}

test('the first connect does not count as a reconnect', function (): void {
    $attempt = MediaReplacementAttempt::factory()->verified()->create();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.media-replacement.attempts.index', absolute: false))
        ->assertNoSmoke()
        ->assertCount(sprintf('[data-attempt-row="%d"]', $attempt->id), 1);
    $webpage->script(appHydratedScript());
    $webpage->script(reloadRecorderScript());

    $webpage->script(liveTransportStateChangeScript('connecting', 'connected'));
    $webpage->wait(1.5);

    expect($webpage->script('window.__mmReloads.length'))->toBe(0);

    // The recorder is wired: a broadcast for the page does reload it.
    $webpage->script(echoEmitScript('admin.media-replacement', 'MediaReplacementAttemptChanged', [
        'id' => $attempt->id, 'status' => 'verified',
    ]));
    $webpage->script(confirmDialogWaitUntilScript('window.__mmReloads.length > 0'));

    expect($webpage->script('window.__mmReloads'))->toContain('attempts,statusCounts');
    $webpage->assertNoSmoke();
});

test('leaving a live page keeps the nav badge listener on the shared channel', function (): void {
    MediaReplacementAttempt::factory()->verified()->create();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.media-replacement.attempts.index', absolute: false))
        ->assertNoSmoke()
        ->assertCount('[data-attempt-row]', 1);
    $webpage->script(appHydratedScript());
    // The page and the nav badge both listen on the shared channel.
    $webpage->script(confirmDialogWaitUntilScript(sprintf('%s >= 2', liveTransportAttemptListenersScript())));

    $before = $webpage->script(liveTransportAttemptListenersScript());

    $webpage->click('[data-nav-item="Dashboard"]')
        ->assertPathIs(route('dashboard', absolute: false));
    // The page's own listener goes with it.
    $webpage->script(confirmDialogWaitUntilScript(sprintf('%s < %d', liveTransportAttemptListenersScript(), $before)));
    $after = $webpage->script(liveTransportAttemptListenersScript());

    expect($before)->toBeGreaterThanOrEqual(2)
        ->and($after)->toBeGreaterThanOrEqual(1)
        ->and($after)->toBeLessThan($before);
    $webpage->assertNoSmoke();
});

test('a restored connection reloads the live props once', function (): void {
    $attempt = MediaReplacementAttempt::factory()->verified()->create();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.media-replacement.attempts.index', absolute: false))
        ->assertNoSmoke()
        ->assertCount(sprintf('[data-attempt-row="%d"]', $attempt->id), 1);
    $webpage->script(appHydratedScript());
    $webpage->script(reloadRecorderScript());

    // Connected first, as on page load: that is not a reconnect (the test above).
    $webpage->script(liveTransportStateChangeScript('connecting', 'connected'));
    $webpage->wait(1);

    expect($webpage->script('window.__mmReloads.length'))->toBe(0);

    $webpage->script(liveTransportStateChangeScript('connected', 'unavailable'));
    $webpage->script(liveTransportStateChangeScript('unavailable', 'connected'));
    $webpage->script(confirmDialogWaitUntilScript('window.__mmReloads.length > 0'));
    $webpage->wait(1.5);

    $reloads = $webpage->script('window.__mmReloads');
    expect($reloads)->toHaveCount(1)
        ->and(explode(',', (string) $reloads[0]))->toContain('attempts', 'statusCounts');
    $webpage->assertNoSmoke();
});
