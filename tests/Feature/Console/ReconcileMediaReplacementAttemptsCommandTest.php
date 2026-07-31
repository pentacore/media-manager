<?php

declare(strict_types=1);

use App\Enums\MediaReplacementStatus;
use App\Enums\UserRole;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    User::factory()->create(['role' => UserRole::Admin]);
});

test('a fresh downloading attempt is left untouched', function (): void {
    $attempt = MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'started_at' => now()->subHour(),
    ]);

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    expect($attempt->fresh()->status)->toBe(MediaReplacementStatus::Downloading);
    Notification::assertNothingSent();
});

test('a stale downloading attempt is flagged needs_attention and notifies admins', function (): void {
    $attempt = MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'started_at' => now()->subHours(9),
    ]);

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($fresh->failure_reason)->toBe('download_timeout')
        ->and($fresh->completed_at)->not->toBeNull();

    Notification::assertSentTimes(MediaReplacementStatusChanged::class, 1);
});

test('a downloading attempt with null started_at uses created_at as the age basis', function (): void {
    $attempt = MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'started_at' => null,
    ]);
    $attempt->forceFill(['created_at' => now()->subHours(12)])->save();

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    expect($attempt->fresh()->status)->toBe(MediaReplacementStatus::NeedsAttention);
});

test('terminal attempts are never touched', function (): void {
    $verified = MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Verified,
        'started_at' => now()->subDays(3),
    ]);
    $failed = MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Failed,
        'started_at' => now()->subDays(3),
    ]);

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    expect($verified->fresh()->status)->toBe(MediaReplacementStatus::Verified)
        ->and($failed->fresh()->status)->toBe(MediaReplacementStatus::Failed);
    Notification::assertNothingSent();
});

test('the sweep restores monitoring the executor suspended', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    $attempt = MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'started_at' => now()->subHours(9),
        'was_monitored' => true,
        'monitoring_suspended' => true,
        'target' => ['service' => 'sonarr', 'series_id' => 42, 'episode_ids' => [7, 8], 'episode_file_ids' => [501]],
    ]);

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($fresh->monitoring_suspended)->toBeFalse();

    // The remonitor call actually reached the arr.
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'episode/monitor'));

    Notification::assertSentTo(
        User::first(),
        MediaReplacementStatusChanged::class,
        fn (MediaReplacementStatusChanged $mediaReplacementStatusChanged): bool => ! str_contains($mediaReplacementStatusChanged->message, 'Monitoring could not be restored'),
    );
});

test('the sweep notification never claims deletion that did not happen', function (): void {
    // grab never confirmed, cleanup never ran -> nothing was deleted.
    MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'started_at' => now()->subHours(9),
        'grab_accepted_at' => null,
        'cleanup_completed_at' => null,
    ]);

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    Notification::assertSentTo(
        User::first(),
        MediaReplacementStatusChanged::class,
        fn (MediaReplacementStatusChanged $mediaReplacementStatusChanged): bool => str_contains($mediaReplacementStatusChanged->message, 'the old file was not removed'),
    );
});

test('a stale downloading attempt an operator retried mid-pass is not flagged as timed out', function (): void {
    // The resume path rewrites started_at to now as its first act — a resumed attempt
    // is a download starting now — but leaves the row `downloading`, so status alone
    // cannot tell a stalled attempt from one that just restarted. Flagging it would
    // regress the live run AND restore monitoring while it is mid-cleanup, so its
    // blocklist would fire the arr's queued re-search against a monitored target.
    // SweepCompetingGrabs cannot catch that: the flagging just made the row terminal.
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);

    $target = ['service' => 'sonarr', 'series_id' => 42, 'episode_ids' => [7, 8], 'episode_file_ids' => [501]];

    // Created first, so its own restore is what fires the fake below and the Retry
    // therefore lands after the selecting query, while the loop is still running.
    $trigger = MediaReplacementAttempt::factory()->create([
        'service_connection_id' => $connection->id,
        'status' => MediaReplacementStatus::Downloading,
        'started_at' => now()->subHours(9),
        'was_monitored' => true,
        'monitoring_suspended' => true,
        'target' => $target,
    ]);

    $retried = MediaReplacementAttempt::factory()->create([
        'service_connection_id' => $connection->id,
        'status' => MediaReplacementStatus::Downloading,
        'started_at' => now()->subHours(9),
        'grab_accepted_at' => now()->subHours(9),
        'cleanup_completed_at' => null,
        'was_monitored' => true,
        'monitoring_suspended' => true,
        'target' => $target,
    ]);

    expect($trigger->id)->toBeLessThan($retried->id);

    Http::fake(function () use ($retried) {
        MediaReplacementAttempt::query()->whereKey($retried->id)->update(['started_at' => now()]);

        return Http::response([], 200);
    });

    // One combined substring, because each expectsOutputToContain() consumes a separate
    // write and the count and the skip are reported on the same line. The skip half is
    // the point: the age clause declining a row must leave a trace, or this pass could
    // silently drop a selected attempt and nobody would know which clause did it.
    $this->artisan('media-replacement:reconcile')
        ->expectsOutputToContain('Flagged 1 stuck media replacement attempt(s) as needs_attention (skipped: 1 settled or restarted between selection and update).')
        ->assertSuccessful();

    // The live run keeps its status AND its suspension: nothing remonitored the target
    // out from under it.
    expect($retried->fresh()->status)->toBe(MediaReplacementStatus::Downloading)
        ->and($retried->fresh()->failure_reason)->toBeNull()
        ->and($retried->fresh()->monitoring_suspended)->toBeTrue()
        ->and($trigger->fresh()->status)->toBe(MediaReplacementStatus::NeedsAttention);
});

test('the custom timeout threshold is respected', function (): void {
    $attempt = MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'started_at' => now()->subHours(3),
    ]);

    $this->artisan('media-replacement:reconcile', ['--hours' => 2])->assertSuccessful();

    expect($attempt->fresh()->status)->toBe(MediaReplacementStatus::NeedsAttention);
});

/**
 * A settled attempt whose monitoring suspension was never lifted. This happens
 * whenever an attempt terminalizes before the executor's finalizeAfterCleanup can
 * restore monitoring — a webhook flagging it mid-cleanup, or a deletion failure
 * that throws first. No import event is coming for a settled attempt, so this
 * command is the only actor left that can put monitoring back.
 *
 * @param  array<string, mixed>  $overrides
 */
function settledSuspendedAttempt(array $overrides = []): MediaReplacementAttempt
{
    return MediaReplacementAttempt::factory()->create(array_replace([
        'status' => MediaReplacementStatus::NeedsAttention,
        'failure_reason' => 'manual_interaction_required',
        'started_at' => now()->subHours(9),
        'completed_at' => now()->subHours(8),
        'was_monitored' => true,
        'monitoring_suspended' => true,
        'target' => ['service' => 'sonarr', 'series_id' => 42, 'episode_ids' => [7, 8], 'episode_file_ids' => [501]],
    ], $overrides));
}

test('a settled attempt keeps its terminal result but has its monitoring restored', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    $attempt = settledSuspendedAttempt();
    $completedAt = $attempt->completed_at;

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    $fresh = $attempt->fresh();

    // Monitoring settled...
    expect($fresh->monitoring_suspended)->toBeFalse();
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_contains((string) $request->url(), 'episode/monitor')
        && $request->data()['monitored'] === true);

    // ...and nothing else touched. The attempt reached needs_attention
    // legitimately and the operator was already told when it did, so this pass
    // must not re-flag, re-terminalize or re-notify it as a timeout.
    expect($fresh->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($fresh->failure_reason)->toBe('manual_interaction_required')
        ->and($fresh->completed_at->equalTo($completedAt))->toBeTrue();
    Notification::assertNothingSent();
});

test('an interrupted cleanup that left deletion_failed also gets its monitoring repaired', function (): void {
    // Pre-existing shape: deleteAfterGrab() marks needs_attention/deletion_failed
    // and then throws, so the executor never reaches the restore at all.
    Http::fake(['*' => Http::response([], 200)]);

    $attempt = settledSuspendedAttempt([
        'failure_reason' => 'deletion_failed',
        'grab_accepted_at' => now()->subHours(9),
        'cleanup_completed_at' => null,
    ]);

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    expect($attempt->fresh()->monitoring_suspended)->toBeFalse()
        ->and($attempt->fresh()->failure_reason)->toBe('deletion_failed');
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'episode/monitor'));
});

test('a settled attempt whose monitoring is already restored is left alone', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    $attempt = settledSuspendedAttempt(['monitoring_suspended' => false]);

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    expect($attempt->fresh()->monitoring_suspended)->toBeFalse();
    // Nothing to settle, so the service is never called.
    Http::assertNothingSent();
});

test('a settled attempt that was never monitored clears the flag without calling the service', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    $attempt = settledSuspendedAttempt(['was_monitored' => false]);

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    // Re-enabling monitoring the user had switched off would be wrong; the stale
    // flag is simply cleared.
    expect($attempt->fresh()->monitoring_suspended)->toBeFalse();
    Http::assertNothingSent();
});

test('a failed restore leaves the settled attempt suspended for the next run', function (): void {
    Http::fake(['*' => Http::response([], 500)]);

    $attempt = settledSuspendedAttempt();

    // The arr is unreachable, but the command must still finish and must not
    // pretend the target is monitored again.
    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    expect($attempt->fresh()->monitoring_suspended)->toBeTrue()
        ->and($attempt->fresh()->status)->toBe(MediaReplacementStatus::NeedsAttention);
    // Hourly schedule: a permanently-unreachable arr must not notify every run.
    Notification::assertNothingSent();
});

test('a failed restore on one settled attempt does not abort the others', function (): void {
    Http::fake(['*' => Http::response([], 500)]);

    $first = settledSuspendedAttempt();
    $second = settledSuspendedAttempt();

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    // Both were attempted rather than the run stopping at the first failure. Each is
    // asserted by its OWN connection url: counting requests would instead measure the
    // client's retry policy, and counting distinct urls would silently collapse on the
    // rare occasion the two factory connections draw the same random port.
    expect($first->fresh()->monitoring_suspended)->toBeTrue()
        ->and($second->fresh()->monitoring_suspended)->toBeTrue();

    foreach ([$first, $second] as $attempt) {
        $url = rtrim((string) $attempt->serviceConnection->url, '/').'/api/v3/episode/monitor';

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && (string) $request->url() === $url);
    }
});

test('a settled attempt reopened while the pass is running is skipped by the re-read', function (): void {
    // An operator Retry reopens the row to `downloading` and re-asserts the
    // suspension for its own resumed cleanup. Restoring monitoring then would strip
    // that live run's protection and let its blocklist trigger the competing
    // auto-search. The age cutoff alone cannot catch this one: the resume DOES rewrite
    // started_at to now, but this pass already selected the row before that write, and
    // a snapshot cannot be retracted. Only re-checking the age against a CURRENT read,
    // immediately before acting, sees it.
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);

    $first = settledSuspendedAttempt(['service_connection_id' => $connection->id]);
    $second = settledSuspendedAttempt(['service_connection_id' => $connection->id]);

    // The Retry lands AFTER the query that selected both rows, while the pass is
    // mid-loop restoring the first. Only a re-read immediately before acting can see
    // that — the selecting query has already run.
    Http::fake(function () use ($first, $second) {
        MediaReplacementAttempt::query()
            ->whereKey([$first->id, $second->id])
            ->update([
                'status' => MediaReplacementStatus::Downloading->value,
                'failure_reason' => null,
                'completed_at' => null,
            ]);

        return Http::response([], 200);
    });

    // Asserted on the pass's own reported skip count. Whichever row the loop reached
    // first was already committed to; the other is reopened by the time the loop
    // re-reads it and is left to that live run. Final flag state cannot be asserted
    // here because the timeout pass legitimately picks the reopened rows up
    // afterwards — and the skip count is what isolates this guard from it.
    $this->artisan('media-replacement:reconcile', ['--hours' => 1])
        ->expectsOutputToContain('skipped: 1 claimed by a live retry')
        ->assertSuccessful();
});

test('the repair pass reports nothing about skips when there were none', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    settledSuspendedAttempt();

    // The old wording printed "(0 skipped as reopened)" on every normal run, which
    // reads as though something was skipped. A cause with a zero count is omitted.
    $this->artisan('media-replacement:reconcile')
        ->doesntExpectOutputToContain('skipped')
        ->assertSuccessful();
});

test('the repair pass distinguishes a row another actor restored from one a retry claimed', function (): void {
    // Two different reasons the re-read declines to act, which the old counter lumped
    // together as "reopened": the first row is still settled but someone else lifted
    // its suspension, the second was reopened by an operator Retry. Reporting both as
    // the same cause would send an operator looking for a retry that never happened.
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);

    // Created FIRST so the loop reaches it first: its restore is what fires the fake
    // below, and both mutations therefore land while the pass is mid-loop, after the
    // query that already selected all three rows.
    $trigger = settledSuspendedAttempt(['service_connection_id' => $connection->id]);
    $restoredElsewhere = settledSuspendedAttempt(['service_connection_id' => $connection->id]);
    $reopened = settledSuspendedAttempt(['service_connection_id' => $connection->id]);

    expect($trigger->id)->toBeLessThan($restoredElsewhere->id)
        ->and($restoredElsewhere->id)->toBeLessThan($reopened->id);

    Http::fake(function () use ($restoredElsewhere, $reopened) {
        MediaReplacementAttempt::query()->whereKey($restoredElsewhere->id)->update(['monitoring_suspended' => false]);
        MediaReplacementAttempt::query()->whereKey($reopened->id)->update([
            'status' => MediaReplacementStatus::Downloading->value,
            'failure_reason' => null,
            'completed_at' => null,
        ]);

        return Http::response([], 200);
    });

    // One substring, because each expectsOutputToContain() consumes a separate write
    // and both causes are reported on the same line.
    $this->artisan('media-replacement:reconcile', ['--hours' => 1])
        ->expectsOutputToContain('(skipped: 1 claimed by a live retry; 1 already restored by another actor)')
        ->assertSuccessful();
});

test('a settled attempt pruned mid-pass does not abort the remaining reconciliation', function (): void {
    // MediaReplacementAttempt is MassPrunable and model:prune is scheduled, so a
    // long-settled row is simultaneously prunable and selectable by this pass and can
    // be deleted between the selecting query and the loop. refresh() resolves through
    // findOrFail, and that ModelNotFoundException was raised OUTSIDE the per-attempt
    // try — it aborted handle() and took the remaining repairs and the whole timeout
    // pass with it.
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);

    // Created first so its restore is what fires the fake, deleting the second row
    // while the pass is already mid-loop.
    $trigger = settledSuspendedAttempt(['service_connection_id' => $connection->id]);
    $pruned = settledSuspendedAttempt(['service_connection_id' => $connection->id]);

    expect($trigger->id)->toBeLessThan($pruned->id);

    $stuck = MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'started_at' => now()->subHours(9),
    ]);

    Http::fake(function () use ($pruned) {
        MediaReplacementAttempt::query()->whereKey($pruned->id)->delete();

        return Http::response([], 200);
    });

    $this->artisan('media-replacement:reconcile')
        ->expectsOutputToContain('1 pruned mid-pass')
        ->assertSuccessful();

    // The pass that runs AFTER the repair still ran, which is what the abort used to
    // swallow.
    expect($trigger->fresh()->monitoring_suspended)->toBeFalse()
        ->and($stuck->fresh()->status)->toBe(MediaReplacementStatus::NeedsAttention);
});

test('the repair pass honours the --hours option rather than a hardcoded cutoff', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    // Two hours old: inside the default 6h cutoff, outside an explicit 1h one. The
    // cutoff does real work now that a resume rewrites started_at to now — a live
    // executor's row is young again by this measure, so the cutoff keeps this pass off
    // it. What THIS test pins is narrower: that the option's value is what decides,
    // not a hardcoded constant. The mid-pass race the cutoff cannot cover is a
    // different test's job (`reopened while the pass is running`).
    $attempt = settledSuspendedAttempt([
        'started_at' => now()->subHours(2),
        'completed_at' => now()->subHours(2),
    ]);

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    // Too young for the 6h default: untouched.
    expect($attempt->fresh()->monitoring_suspended)->toBeTrue();
    Http::assertNothingSent();

    $this->artisan('media-replacement:reconcile', ['--hours' => 1])->assertSuccessful();

    // Old enough for a 1h cutoff: repaired.
    expect($attempt->fresh()->monitoring_suspended)->toBeFalse();
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'episode/monitor'));
});
