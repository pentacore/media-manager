<?php

declare(strict_types=1);

use App\Jobs\ClearSeerrRequests;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
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

    new ClearSeerrRequests($this->connection->id, 'available', [11, 22], $admin->id)->handle();

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

    new ClearSeerrRequests($this->connection->id, 'declined', [11, 22], null)->handle();

    expect(ActivityLog::query()->where('action', 'seerr.requests_cleared')->sole()->metadata)
        ->toMatchArray(['deleted' => 1, 'failed' => 1]);
});

test('it stops calling seerr after repeated connection failures', function (): void {
    Sleep::fake();
    Http::fake(['seerr.local:5055/*' => Http::failedConnection()]);
    $ids = range(1, ClearSeerrRequests::MAX_CONSECUTIVE_CONNECTION_FAILURES + 5);

    new ClearSeerrRequests($this->connection->id, 'completed', $ids, null)->handle();

    // 3 attempts per id (client retry) for the first N ids, then no more calls.
    Http::assertSentCount(ClearSeerrRequests::MAX_CONSECUTIVE_CONNECTION_FAILURES * 3);
    expect(ActivityLog::query()->where('action', 'seerr.requests_cleared')->sole()->metadata)
        ->toMatchArray(['deleted' => 0, 'failed' => count($ids)]);
});

test('it does nothing for a deactivated connection', function (): void {
    $this->connection->update(['is_active' => false]);
    Http::fake();

    new ClearSeerrRequests($this->connection->id, 'available', [11], null)->handle();

    Http::assertNothingSent();
    expect(ActivityLog::query()->where('action', 'seerr.requests_cleared')->exists())->toBeFalse();
});
