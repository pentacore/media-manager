<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Library\InterventionCounter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    // The shared nav badge would otherwise walk both arr queues on every request.
    Cache::put(InterventionCounter::CACHE_KEY, 0, 600);
    $this->sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k', 'name' => 'Sonarr']);
    $this->radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k', 'name' => 'Radarr']);
    $this->admin = User::factory()->admin()->create();
});

/**
 * @param  list<int>  $failingIds  DELETE answers 503 (a transport-level outage)
 * @param  list<int>  $refusedIds  DELETE answers 404 (a refusal, not an outage)
 */
function fakeBulkSonarrQueue(array $failingIds = [], array $refusedIds = []): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => function (Request $request) use ($failingIds, $refusedIds) {
            if ($request->method() === 'DELETE') {
                $queueId = (int) Str::before(Str::afterLast($request->url(), '/'), '?');

                if (in_array($queueId, $failingIds, true)) {
                    return Http::response(['message' => 'upstream body /data/secret'], 503);
                }

                if (in_array($queueId, $refusedIds, true)) {
                    return Http::response(['message' => 'Queue item not found'], 404);
                }

                return Http::response('', 200);
            }

            return Http::response(['records' => [
                ['id' => 41, 'title' => 'Severance.S01E01.1080p', 'series' => ['title' => 'Severance']],
                ['id' => 42, 'title' => 'Andor.S01E01.1080p', 'series' => ['title' => 'Andor']],
            ]]);
        },
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
        'radarr.local:7878/api/v3/queue*' => Http::response(['records' => []]),
        'radarr.local:7878/api/v3/history*' => Http::response(['records' => []]),
    ]);
}

/**
 * @param  list<int>  $failingIds
 */
function fakeBulkRadarrQueue(array $failingIds = []): void
{
    Http::fake([
        'radarr.local:7878/api/v3/queue*' => function (Request $request) use ($failingIds) {
            if ($request->method() === 'DELETE') {
                $queueId = (int) Str::before(Str::afterLast($request->url(), '/'), '?');

                return in_array($queueId, $failingIds, true)
                    ? Http::response(['message' => 'upstream body /data/secret'], 503)
                    : Http::response('', 200);
            }

            return Http::response(['records' => [
                ['id' => 51, 'title' => 'Dune.2021.1080p', 'movie' => ['title' => 'Dune']],
                ['id' => 52, 'title' => 'Arrival.2016.1080p', 'movie' => ['title' => 'Arrival']],
            ]]);
        },
        'radarr.local:7878/api/v3/history*' => Http::response(['records' => []]),
        'sonarr.local:8989/api/v3/queue*' => Http::response(['records' => []]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
    ]);
}

/**
 * Bulk is pinned to the connection the queue rows were rendered from; by
 * default, the first connection of the requested service.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bulkQueuePayload(array $overrides = []): array
{
    $service = (string) ($overrides['service'] ?? 'sonarr');

    return [
        'service' => $service,
        'service_connection_id' => ServiceConnection::query()->where('type', $service)->orderBy('id')->value('id'),
        'ids' => [41, 42],
        'action' => 'remove',
        ...$overrides,
    ];
}

test('an admin removes several Sonarr queue items, each removed and audited', function (): void {
    fakeBulkSonarrQueue();

    $this->actingAs($this->admin)
        ->postJson(route('media.library.activity.queue.bulk'), bulkQueuePayload())
        ->assertOk()
        ->assertJsonPath('started', 2)
        ->assertJsonPath('toast.message', '2 removed');

    foreach ([41, 42] as $queueId) {
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), sprintf('/api/v3/queue/%d?', $queueId))
            && str_contains($request->url(), 'blocklist=false')
            && str_contains($request->url(), 'skipRedownload=true'));
    }

    expect(ActivityLog::query()->where('category', 'audit')->where('action', 'queue.removed')->count())->toBe(2);
});

test('blocklisting in bulk blocklists and lets a fresh search run', function (): void {
    fakeBulkSonarrQueue();

    $this->actingAs($this->admin)
        ->postJson(route('media.library.activity.queue.bulk'), bulkQueuePayload(['action' => 'blocklist']))
        ->assertJsonPath('toast.message', '2 blocklisted');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), 'blocklist=true')
        && str_contains($request->url(), 'skipRedownload=false'));

    expect(ActivityLog::query()->where('category', 'audit')->where('action', 'queue.blocklisted')->count())->toBe(2);
});

test('an unreachable item is named from the queue while the rest are removed', function (): void {
    Sleep::fake();
    fakeBulkSonarrQueue(failingIds: [42]);

    $response = $this->actingAs($this->admin)
        ->postJson(route('media.library.activity.queue.bulk'), bulkQueuePayload())
        ->assertJsonPath('started', 1)
        ->assertJsonPath('failed', [['id' => 42, 'title' => 'Andor', 'reason' => 'Sonarr is unreachable right now.']]);

    expect($response->getContent())->not->toContain('upstream body');
});

test('the audit row pins the resolved connection as subject, with the 7a context shape and the acting admin', function (): void {
    fakeBulkSonarrQueue();

    $this->actingAs($this->admin)
        ->postJson(route('media.library.activity.queue.bulk'), bulkQueuePayload(['ids' => [41]]));

    $activityLog = ActivityLog::query()->where('category', 'audit')->where('action', 'queue.removed')->sole();

    expect($activityLog->subject_type)->toBe(ServiceConnection::class)
        ->and($activityLog->subject_id)->toBe($this->sonarr->id)
        ->and($activityLog->description)->toBe('Removed Sonarr queue item 41.')
        ->and($activityLog->metadata['context'])->toBe(['service' => 'sonarr', 'queue_id' => 41])
        ->and($activityLog->user_id)->toBe($this->admin->id);
});

test('an outage short-circuits the rest of the batch after the first unreachable id', function (): void {
    Sleep::fake();
    $deleteAttempts = 0;

    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => function (Request $request) use (&$deleteAttempts) {
            if ($request->method() === 'DELETE') {
                $deleteAttempts++;

                throw new ConnectionException('Connection refused.');
            }

            return Http::response(['records' => [
                ['id' => 41, 'title' => 'Severance.S01E01.1080p', 'series' => ['title' => 'Severance']],
                ['id' => 42, 'title' => 'Andor.S01E01.1080p', 'series' => ['title' => 'Andor']],
                ['id' => 43, 'title' => 'The.Bear.S01E01.1080p', 'series' => ['title' => 'The Bear']],
            ]]);
        },
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
    ]);

    $this->actingAs($this->admin)
        ->postJson(route('media.library.activity.queue.bulk'), bulkQueuePayload(['ids' => [41, 42, 43]]))
        ->assertJsonPath('started', 0)
        ->assertJsonPath('failed', [
            ['id' => 41, 'title' => '#41', 'reason' => 'Sonarr is unreachable right now.'],
            ['id' => 42, 'title' => '#42', 'reason' => 'Sonarr is unreachable right now.'],
            ['id' => 43, 'title' => '#43', 'reason' => 'Sonarr is unreachable right now.'],
        ]);

    // Only the first id's DELETE (and its internal HTTP-client retries)
    // reaches Sonarr; ids 42 and 43 are failed locally without a request.
    expect($deleteAttempts)->toBe(3);

    // The failure titles never go back to the unreachable host for the queue.
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/api/v3/queue'));
});

test('two consecutive server errors trip the outage short-circuit', function (): void {
    Sleep::fake();
    fakeBulkSonarrQueue(failingIds: [41, 42]);

    $this->actingAs($this->admin)
        ->postJson(route('media.library.activity.queue.bulk'), bulkQueuePayload(['ids' => [41, 42, 43]]))
        ->assertJsonPath('started', 0)
        ->assertJsonPath('failed', [
            ['id' => 41, 'title' => 'Severance', 'reason' => 'Sonarr is unreachable right now.'],
            ['id' => 42, 'title' => 'Andor', 'reason' => 'Sonarr is unreachable right now.'],
            ['id' => 43, 'title' => '#43', 'reason' => 'Sonarr is unreachable right now.'],
        ]);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/43?'));
});

test('a success between two server errors resets the outage count', function (): void {
    Sleep::fake();
    fakeBulkSonarrQueue(failingIds: [41, 43]);

    $this->actingAs($this->admin)
        ->postJson(route('media.library.activity.queue.bulk'), bulkQueuePayload(['ids' => [41, 42, 43, 44]]))
        ->assertJsonPath('started', 2);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/44?'));
});

test('a single server error (RequestException) never trips the outage short-circuit', function (): void {
    Sleep::fake();
    fakeBulkSonarrQueue(failingIds: [41]);

    $this->actingAs($this->admin)
        ->postJson(route('media.library.activity.queue.bulk'), bulkQueuePayload(['ids' => [41, 42]]))
        ->assertJsonPath('started', 1)
        ->assertJsonPath('failed', [['id' => 41, 'title' => 'Severance', 'reason' => 'Sonarr is unreachable right now.']]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/42?'));
});

test('a 4xx refusal (RequestException) is worded as a refusal and never trips the outage short-circuit', function (): void {
    fakeBulkSonarrQueue(refusedIds: [41]);

    $this->actingAs($this->admin)
        ->postJson(route('media.library.activity.queue.bulk'), bulkQueuePayload(['ids' => [41, 42]]))
        ->assertJsonPath('started', 1)
        ->assertJsonPath('failed', [['id' => 41, 'title' => 'Severance', 'reason' => 'Sonarr refused the change.']]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/42?'));
});

test('a Radarr bulk run deletes from the Radarr queue and names a failed item from it', function (): void {
    Sleep::fake();
    fakeBulkRadarrQueue(failingIds: [52]);

    $this->actingAs($this->admin)
        ->postJson(route('media.library.activity.queue.bulk'), bulkQueuePayload(['service' => 'radarr', 'ids' => [51, 52]]))
        ->assertJsonPath('started', 1)
        ->assertJsonPath('failed', [['id' => 52, 'title' => 'Arrival', 'reason' => 'Radarr is unreachable right now.']]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), 'radarr.local:7878/api/v3/queue/51?'));
});

test('a bulk queue action pinned to a connection that is gone, deactivated or another service is refused and nothing is sent', function (int $pinnedConnectionId): void {
    $this->actingAs($this->admin)
        ->postJson(route('media.library.activity.queue.bulk'), bulkQueuePayload(['service' => 'radarr', 'service_connection_id' => $pinnedConnectionId]))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'That Radarr connection is unavailable — refresh and try again.');

    Http::assertNothingSent();
})->with([
    'deleted' => [fn (): int => 999_999],
    'deactivated' => [function (): int {
        $serviceConnection = ServiceConnection::query()->where('type', 'radarr')->sole();
        ServiceConnection::query()->whereKey($serviceConnection->id)->update(['is_active' => false]);

        return $serviceConnection->id;
    }],
    'another service' => [fn (): int => ServiceConnection::query()->where('type', 'sonarr')->sole()->id],
]);

test('a bulk queue action acts on the pinned connection, not the active one', function (): void {
    $secondSonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr-4k.local:8989', 'api_key' => 'k']);
    Http::fake(['sonarr-4k.local:8989/api/v3/queue*' => Http::response('', 200)]);

    $this->actingAs($this->admin)
        ->postJson(route('media.library.activity.queue.bulk'), bulkQueuePayload(['service_connection_id' => $secondSonarr->id, 'ids' => [41]]))
        ->assertOk()
        ->assertJsonPath('started', 1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), 'sonarr-4k.local:8989/api/v3/queue/41?'));
});

test('an invalid bulk payload is refused before anything is sent', function (array $overrides): void {
    fakeBulkSonarrQueue();

    $this->actingAs($this->admin)
        ->postJson(route('media.library.activity.queue.bulk'), bulkQueuePayload($overrides))
        ->assertUnprocessable();

    Http::assertNothingSent();
})->with([
    'duplicate ids' => [['ids' => [41, 41]]],
    '101 ids' => [['ids' => range(1, 101)]],
    'empty ids' => [['ids' => []]],
    'a non-integer id' => [['ids' => ['abc']]],
    'an unknown action' => [['action' => 'purge']],
    'an unknown service' => [['service' => 'bazarr']],
    'no connection pin' => [['service_connection_id' => null]],
]);

test('members cannot remove queue items in bulk', function (): void {
    $this->actingAs(User::factory()->member()->create())
        ->postJson(route('media.library.activity.queue.bulk'), bulkQueuePayload())
        ->assertForbidden();

    Http::assertNothingSent();
});
