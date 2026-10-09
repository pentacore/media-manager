<?php

declare(strict_types=1);

use App\Jobs\ClearSeerrRequests;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Seerr\SeerrClearInFlight;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->connection = ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'test-api-key',
    ]);
});

test('it deletes every request and records the outcome', function (): void {
    $admin = User::factory()->admin()->create();
    Http::fake(['seerr.local:5055/api/v1/request/*' => Http::response(['ok' => true])]);

    new ClearSeerrRequests($this->connection->id, 'available', [11, 22], $admin->id, 'unclaimed-slot-token')->handle();

    Http::assertSentCount(2);
    $activityLog = ActivityLog::query()->where('action', 'seerr.requests_cleared')->sole();
    expect($activityLog->user_id)->toBe($admin->id)
        ->and($activityLog->metadata)->toMatchArray(['status' => 'available', 'deleted' => 2, 'failed' => 0]);
});

test('upstream rejections are counted as failures', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/request/11' => Http::response(['message' => 'nope'], 404),
        'seerr.local:5055/api/v1/request/22' => Http::response(['ok' => true]),
    ]);

    new ClearSeerrRequests($this->connection->id, 'declined', [11, 22], null, 'unclaimed-slot-token')->handle();

    expect(ActivityLog::query()->where('action', 'seerr.requests_cleared')->sole()->metadata)
        ->toMatchArray(['deleted' => 1, 'failed' => 1]);
});

test('it stops calling seerr after repeated connection failures', function (): void {
    Sleep::fake();
    Http::fake(['seerr.local:5055/*' => Http::failedConnection()]);
    $ids = range(1, ClearSeerrRequests::MAX_CONSECUTIVE_CONNECTION_FAILURES + 5);

    new ClearSeerrRequests($this->connection->id, 'completed', $ids, null, 'unclaimed-slot-token')->handle();

    // One attempt per id (a DELETE is never retried) for the first N ids,
    // then no more calls.
    Http::assertSentCount(ClearSeerrRequests::MAX_CONSECUTIVE_CONNECTION_FAILURES);
    expect(ActivityLog::query()->where('action', 'seerr.requests_cleared')->sole()->metadata)
        ->toMatchArray(['deleted' => 0, 'failed' => count($ids)]);
});

test('it does nothing for a deactivated connection', function (): void {
    $this->connection->update(['is_active' => false]);
    Http::fake();

    new ClearSeerrRequests($this->connection->id, 'available', [11], null, 'unclaimed-slot-token')->handle();

    Http::assertNothingSent();
    expect(ActivityLog::query()->where('action', 'seerr.requests_cleared')->exists())->toBeFalse();
});

test('it frees the clear slot when it finishes', function (): void {
    Http::fake(['seerr.local:5055/api/v1/request/*' => Http::response(['ok' => true])]);
    $slotToken = SeerrClearInFlight::claim($this->connection->id, 'available');

    new ClearSeerrRequests($this->connection->id, 'available', [11], null, $slotToken)->handle();

    expect(SeerrClearInFlight::claim($this->connection->id, 'available'))->not->toBeNull();
});

test('it frees the clear slot even when it bails out early', function (): void {
    $this->connection->update(['is_active' => false]);
    $slotToken = SeerrClearInFlight::claim($this->connection->id, 'available');

    new ClearSeerrRequests($this->connection->id, 'available', [11], null, $slotToken)->handle();

    expect(SeerrClearInFlight::claim($this->connection->id, 'available'))->not->toBeNull();
    Http::assertNothingSent();
});

test('it frees the clear slot when the worker gives up on it', function (): void {
    $slotToken = SeerrClearInFlight::claim($this->connection->id, 'failed');

    new ClearSeerrRequests($this->connection->id, 'failed', [11], null, $slotToken)->failed(new RuntimeException('Timed out.'));

    expect(SeerrClearInFlight::claim($this->connection->id, 'failed'))->not->toBeNull();
});

test('an old job never frees the slot a newer clear holds', function (): void {
    Http::fake(['seerr.local:5055/api/v1/request/*' => Http::response(['ok' => true])]);
    $staleToken = SeerrClearInFlight::claim($this->connection->id, 'available');
    $this->travel(SeerrClearInFlight::TTL_SECONDS + 1)->seconds();
    $newerToken = SeerrClearInFlight::claim($this->connection->id, 'available');

    // A late finish, a manual queue:retry and a give-up of the old job.
    new ClearSeerrRequests($this->connection->id, 'available', [11], null, $staleToken)->handle();
    new ClearSeerrRequests($this->connection->id, 'available', [11], null, $staleToken)->failed(new RuntimeException('Timed out.'));

    expect($newerToken)->not->toBeNull()->not->toBe($staleToken)
        ->and(SeerrClearInFlight::claim($this->connection->id, 'available'))->toBeNull()
        ->and(SeerrClearInFlight::refresh($this->connection->id, 'available', $staleToken))->toBeFalse();
});

test('refreshing the slot restarts its TTL', function (): void {
    $slotToken = SeerrClearInFlight::claim($this->connection->id, 'pending');

    $this->travel(600)->seconds();
    expect(SeerrClearInFlight::refresh($this->connection->id, 'pending', $slotToken))->toBeTrue();

    $this->travel(SeerrClearInFlight::TTL_SECONDS - 1)->seconds();
    expect(SeerrClearInFlight::claim($this->connection->id, 'pending'))->toBeNull();

    $this->travel(2)->seconds();
    expect(SeerrClearInFlight::claim($this->connection->id, 'pending'))->not->toBeNull();
});
