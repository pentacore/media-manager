<?php

declare(strict_types=1);

use App\Models\MediaReplacementAttempt;
use App\Models\User;

/*
 * Functional coverage for Admin → Media Replacement → Attempts
 * (resources/js/pages/Admin/MediaReplacement/Attempts/{Index,Show}.vue) and
 * the tab strip shared with the settings page. Realtime refreshes are not
 * exercised here (no Reverb broker in the test env — see RealtimeFlowsTest);
 * the broadcast contract is covered in tests/Feature/Broadcasting.
 */

test('the settings page links to the attempts tab and the tab carries the attention badge', function (): void {
    MediaReplacementAttempt::factory()->needsAttention()->create();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.media-replacement.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-tab="settings"]', 'Settings')
        ->assertSeeIn('[data-attempts-tab-badge]', '1')
        ->click('[data-tab="attempts"]')
        ->assertPathIs(route('admin.media-replacement.attempts.index', absolute: false))
        // `data-attempts-title` rather than a bare `h1`: Pest's GuessLocator
        // only treats a selector as CSS when it starts with (or contains) a CSS
        // special character, so `h1` degrades to getByText('h1', exact: true)
        // and never matches the heading. Any attribute selector is explicit.
        ->assertSeeIn('[data-attempts-title]', 'Replacement attempts')
        ->assertNoSmoke();
});

test('the attempts list renders rows, filters by status and opens the detail page', function (): void {
    $verified = MediaReplacementAttempt::factory()->verified()->create();
    $attention = MediaReplacementAttempt::factory()->needsAttention()->create();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.media-replacement.attempts.index', absolute: false))
        ->assertNoSmoke()
        ->assertCount('[data-attempt-row]', 2)
        ->assertSeeIn(sprintf('[data-attempt-row="%d"]', $verified->id), 'Verified')
        ->assertSeeIn(sprintf('[data-attempt-row="%d"]', $attention->id), 'download_timeout')
        ->click('[data-status-count="needs_attention"]')
        ->assertCount('[data-attempt-row]', 1)
        ->assertCount(sprintf('[data-attempt-row="%d"]', $attention->id), 1)
        ->click(sprintf('[data-attempt-view="%d"]', $attention->id))
        ->assertPathIs(route('admin.media-replacement.attempts.show', $attention, absolute: false))
        ->assertSeeIn('[data-attempt-header]', 'Trusted Anime S01E01')
        ->assertNoSmoke();
});

test('the detail page shows every card and acknowledging hides the button', function (): void {
    $attempt = MediaReplacementAttempt::factory()->needsAttention()->monitoringSuspended()->create([
        'download_id' => 'ABC123',
    ]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.media-replacement.attempts.show', $attempt, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-attempt-timeline]', 'ABC123')
        ->assertSeeIn('[data-attempt-target]', 'OLD')
        ->assertSeeIn('[data-attempt-candidate]', 'Show S01E01 CR')
        ->assertSeeIn('[data-attempt-verification]', 'Not verified yet.')
        ->assertSeeIn('[data-attempt-monitoring]', 'still suspended')
        ->assertCount('[data-action="acknowledge"]', 1)
        ->assertCount('[data-action="restore-monitoring"]', 1)
        ->assertCount('[data-action="cancel"]', 0)
        ->click('[data-action="acknowledge"]')
        ->assertSee('Attempt acknowledged.')
        ->assertCount('[data-action="acknowledge"]', 0)
        ->assertSeeIn('[data-attempt-header]', 'Acknowledged by')
        ->assertNoSmoke();

    expect($attempt->fresh()->acknowledged_at)->not->toBeNull();
});

test('cancelling an in-flight attempt asks for confirmation first', function (): void {
    $attempt = MediaReplacementAttempt::factory()->downloading()->create([
        'download_id' => null,
        'monitoring_suspended' => null,
    ]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.media-replacement.attempts.show', $attempt, absolute: false))
        ->assertNoSmoke()
        ->assertCount('[data-action="cancel"]', 1)
        ->click('[data-action="cancel"]')
        ->assertSee('Cancel this replacement?')
        ->click('[data-action="cancel-confirm"]')
        ->assertSee('Attempt cancelled.')
        ->assertSeeIn('[data-attempt-header]', 'cancelled_by_operator')
        ->assertNoSmoke();

    expect($attempt->fresh()->failure_reason)->toBe('cancelled_by_operator');
});

test('the action queue links a replacement request to its attempt for admins', function (): void {
    $attempt = MediaReplacementAttempt::factory()->failed()->create();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', ['request' => $attempt->action_request_id], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-replacement-attempt-status]', 'failed')
        ->click('[data-replacement-attempt-link]')
        ->assertPathIs(route('admin.media-replacement.attempts.show', $attempt, absolute: false))
        ->assertNoSmoke();
});
