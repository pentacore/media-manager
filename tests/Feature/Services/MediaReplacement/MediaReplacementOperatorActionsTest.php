<?php

declare(strict_types=1);

use App\Enums\MediaReplacementStatus;
use App\Events\MediaReplacementAttemptChanged;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\MediaReplacement\MediaReplacementExecutionLock;
use App\Services\MediaReplacement\MediaReplacementOperatorActions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
    Event::fake([MediaReplacementAttemptChanged::class]);

    $this->connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);
    $this->admin = User::factory()->admin()->create();
});

function operatorActions(): MediaReplacementOperatorActions
{
    return resolve(MediaReplacementOperatorActions::class);
}

/**
 * A queue page of rows that are nobody's replacement, ids $from..$from+$count-1.
 *
 * @return list<array<string, mixed>>
 */
function operatorActionsQueuePage(int $from, int $count): array
{
    return array_map(
        static fn (int $id): array => ['id' => $id, 'downloadId' => 'OTHER-'.$id, 'title' => 'Something.Else.'.$id],
        range($from, $from + $count - 1),
    );
}

test('acknowledge stamps the row, keeps the status and announces the change', function (): void {
    $attempt = MediaReplacementAttempt::factory()->needsAttention()->create(['service_connection_id' => $this->connection->id]);

    $result = operatorActions()->acknowledge($attempt, $this->admin);

    $fresh = $attempt->fresh();
    expect($result->ok)->toBeTrue()
        ->and($fresh->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($fresh->acknowledged_at)->not->toBeNull()
        ->and($fresh->acknowledged_by)->toBe($this->admin->id)
        ->and($fresh->failure_reason)->toBe('download_timeout');
    Event::assertDispatched(MediaReplacementAttemptChanged::class, fn (MediaReplacementAttemptChanged $event): bool => $event->mediaReplacementAttempt->is($attempt));
});

test('acknowledge refuses an already acknowledged or settled attempt', function (MediaReplacementAttempt $attempt): void {
    $result = operatorActions()->acknowledge($attempt, $this->admin);

    expect($result->ok)->toBeFalse();
    Event::assertNotDispatched(MediaReplacementAttemptChanged::class);
})->with([
    'already acknowledged' => fn (): MediaReplacementAttempt => MediaReplacementAttempt::factory()->needsAttention()->acknowledged()->create(),
    'verified' => fn (): MediaReplacementAttempt => MediaReplacementAttempt::factory()->verified()->create(),
]);

test('restore monitoring refuses when nothing is suspended', function (): void {
    $attempt = MediaReplacementAttempt::factory()->verified()->create(['service_connection_id' => $this->connection->id]);

    $result = operatorActions()->restoreMonitoring($attempt);

    expect($result->ok)->toBeFalse();
    Http::assertNothingSent();
    Event::assertNotDispatched(MediaReplacementAttemptChanged::class);
});

test('restore monitoring re-monitors the target episodes and clears the flag', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/episode/monitor' => Http::response([], 202)]);
    $attempt = MediaReplacementAttempt::factory()->needsAttention()->monitoringSuspended()->create(['service_connection_id' => $this->connection->id]);

    $result = operatorActions()->restoreMonitoring($attempt);

    expect($result->ok)->toBeTrue()
        ->and($attempt->fresh()->monitoring_suspended)->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/api/v3/episode/monitor')
        && $request['episodeIds'] === [101]
        && $request['monitored'] === true);
    Event::assertDispatched(MediaReplacementAttemptChanged::class);
});

test('restore monitoring reports a failed arr call and keeps the flag', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/episode/monitor' => Http::response([], 500)]);
    $attempt = MediaReplacementAttempt::factory()->needsAttention()->monitoringSuspended()->create(['service_connection_id' => $this->connection->id]);

    $result = operatorActions()->restoreMonitoring($attempt);

    expect($result->ok)->toBeFalse()
        ->and($attempt->fresh()->monitoring_suspended)->toBeTrue();
    Event::assertNotDispatched(MediaReplacementAttemptChanged::class);
});

test('cancel refuses a settled attempt', function (): void {
    $attempt = MediaReplacementAttempt::factory()->verified()->create(['service_connection_id' => $this->connection->id]);

    expect(operatorActions()->cancel($attempt)->ok)->toBeFalse()
        ->and($attempt->fresh()->status)->toBe(MediaReplacementStatus::Verified);
    Http::assertNothingSent();
});

test('cancel refuses while the executor holds the execution lock', function (): void {
    $attempt = MediaReplacementAttempt::factory()->downloading()->create(['service_connection_id' => $this->connection->id]);
    Cache::lock(MediaReplacementExecutionLock::key($attempt->action_request_id), MediaReplacementExecutionLock::TTL_SECONDS)->get();

    $result = operatorActions()->cancel($attempt);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('executor')
        ->and($attempt->fresh()->status)->toBe(MediaReplacementStatus::Downloading);
    Http::assertNothingSent();
});

test('cancel removes our queue row without blocklisting, restores monitoring and fails the attempt', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/queue/7*' => Http::response([], 200),
        'sonarr.local:8989/api/v3/queue?*' => Http::response([
            'totalRecords' => 2,
            'records' => [
                ['id' => 7, 'downloadId' => 'abc123', 'title' => 'Trusted.Anime.S01E01.CR'],
                ['id' => 8, 'downloadId' => 'OTHER', 'title' => 'Something.Else'],
            ],
        ]),
        'sonarr.local:8989/api/v3/episode/monitor' => Http::response([], 202),
    ]);
    $attempt = MediaReplacementAttempt::factory()->downloading()->monitoringSuspended()->create([
        'service_connection_id' => $this->connection->id,
        'download_id' => 'ABC123',
    ]);

    $result = operatorActions()->cancel($attempt);

    $fresh = $attempt->fresh();
    expect($result->ok)->toBeTrue()
        ->and($result->message)->toBe('Attempt cancelled.')
        ->and($fresh->status)->toBe(MediaReplacementStatus::Failed)
        ->and($fresh->failure_reason)->toBe(MediaReplacementOperatorActions::CANCELLED_BY_OPERATOR)
        ->and($fresh->completed_at)->not->toBeNull()
        ->and($fresh->monitoring_suspended)->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/7?')
        && str_contains($request->url(), 'removeFromClient=true')
        && str_contains($request->url(), 'blocklist=false')
        && str_contains($request->url(), 'skipRedownload=true'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/queue/8'));
    Event::assertDispatched(MediaReplacementAttemptChanged::class);
});

test('cancel pages through the queue and removes a row a later page reports under a numeric-string id', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/queue/7000*' => Http::response([], 200),
        'sonarr.local:8989/api/v3/queue?*' => Http::sequence()
            // A full page of strangers, and a `totalRecords` the arr serialised
            // as a string, which must still page and still bound the loop.
            ->push(['totalRecords' => '201', 'records' => operatorActionsQueuePage(1, 200)])
            ->push(['totalRecords' => '201', 'records' => [
                ['id' => '7000', 'downloadId' => 'abc123', 'title' => 'Trusted.Anime.S01E01.CR'],
            ]]),
    ]);
    $attempt = MediaReplacementAttempt::factory()->downloading()->create([
        'service_connection_id' => $this->connection->id,
        'download_id' => 'ABC123',
        'monitoring_suspended' => null,
    ]);

    $result = operatorActions()->cancel($attempt);

    expect($result->ok)->toBeTrue()
        ->and($result->message)->toBe('Attempt cancelled.')
        ->and($attempt->fresh()->status)->toBe(MediaReplacementStatus::Failed);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/7000?'));
});

test('cancel stops paging when a page repeats itself and reports no total', function (): void {
    // The runaway shape: a full page every time, no usable totalRecords, and an
    // upstream ignoring `page`. Only the no-new-ids guard can end this.
    Http::fake([
        'sonarr.local:8989/api/v3/queue?*' => Http::sequence()
            ->push(['records' => operatorActionsQueuePage(1, 200)])
            ->push(['records' => operatorActionsQueuePage(1, 200)]),
    ]);
    $attempt = MediaReplacementAttempt::factory()->downloading()->create([
        'service_connection_id' => $this->connection->id,
        'download_id' => 'ABC123',
        'monitoring_suspended' => null,
    ]);

    // A third GET exhausts the sequence and throws, so a runaway fails loudly
    // rather than hanging the suite.
    $result = operatorActions()->cancel($attempt);

    expect($result->ok)->toBeTrue()
        ->and($result->message)->toBe('Attempt cancelled.')
        ->and($attempt->fresh()->status)->toBe(MediaReplacementStatus::Failed);
    Http::assertSentCount(2);
});

test('cancel still fails the attempt when the arr queue is unreachable', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/queue?*' => Http::response([], 500)]);
    $attempt = MediaReplacementAttempt::factory()->downloading()->create([
        'service_connection_id' => $this->connection->id,
        'download_id' => 'ABC123',
        'monitoring_suspended' => null,
    ]);

    $result = operatorActions()->cancel($attempt);

    expect($result->ok)->toBeTrue()
        ->and($result->message)->toContain('could not be removed')
        ->and($attempt->fresh()->status)->toBe(MediaReplacementStatus::Failed);
});

test('cancel without a download id touches no arr endpoint', function (): void {
    $attempt = MediaReplacementAttempt::factory()->downloading()->create([
        'service_connection_id' => $this->connection->id,
        'download_id' => null,
        'monitoring_suspended' => null,
    ]);

    expect(operatorActions()->cancel($attempt)->ok)->toBeTrue()
        ->and($attempt->fresh()->status)->toBe(MediaReplacementStatus::Failed);
    Http::assertNothingSent();
});

test('cancel yields to a webhook that settled the attempt first', function (): void {
    $attempt = MediaReplacementAttempt::factory()->downloading()->create([
        'service_connection_id' => $this->connection->id,
        'download_id' => null,
        'monitoring_suspended' => null,
    ]);
    // Simulate the race: the row settles between the caller's read and the lock.
    MediaReplacementAttempt::query()->whereKey($attempt->id)->update([
        'status' => MediaReplacementStatus::Verified->value,
        'completed_at' => now(),
    ]);

    $result = operatorActions()->cancel($attempt);

    expect($result->ok)->toBeFalse()
        ->and($attempt->fresh()->status)->toBe(MediaReplacementStatus::Verified);
    Event::assertNotDispatched(MediaReplacementAttemptChanged::class);
});
