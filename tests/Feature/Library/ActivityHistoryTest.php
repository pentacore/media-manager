<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Library\InterventionCounter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    // The shared nav badge would otherwise walk both arr queues on every request.
    Cache::put(InterventionCounter::CACHE_KEY, 0, 600);
    $this->sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'sonarr-key']);
    $this->radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'radarr-key']);
});

/**
 * @return array<string, mixed>
 */
function activityHistorySonarrRecord(int $id, string $eventType = 'grabbed'): array
{
    return [
        'id' => $id,
        'eventType' => $eventType,
        'sourceTitle' => 'Severance.S02E07.1080p.WEB',
        'series' => ['title' => 'Severance'],
        'episode' => ['seasonNumber' => 2, 'episodeNumber' => 7, 'title' => 'Chikhai Bardo'],
        'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
        'date' => '2026-09-28T08:00:00Z',
        'downloadClient' => 'SABnzbd',
        'data' => [
            'indexer' => 'NZBgeek',
            'downloadUrl' => 'https://api.nzbgeek.info/api?t=get&id=1&apikey=indexer-secret',
            'guid' => 'https://tracker.example/details/1?passkey=tracker-passkey',
        ],
    ];
}

test('the Sonarr tab loads one exactly paged history page', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/history*' => Http::response(['page' => 2, 'pageSize' => 50, 'totalRecords' => 120, 'records' => [activityHistorySonarrRecord(55)]]),
    ]);

    $this->actingAs(User::factory()->member()->create())
        ->get(route('media.library.activity.queue', ['history_service' => 'sonarr', 'history_page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library/Activity')
            ->where('historyFilters', ['service' => 'sonarr', 'page' => 2, 'active' => true])
            ->loadDeferredProps('history', fn ($page) => $page
                ->where('history.service', 'sonarr')
                ->where('history.configured', true)
                ->where('history.connection_id', $this->sonarr->id)
                ->where('history.page', 2)
                ->where('history.page_size', 50)
                ->where('history.total', 120)
                ->where('history.error', null)
                ->where('history.rows.0.id', 55)
                ->where('history.rows.0.event_type', 'grabbed')
                ->where('history.rows.0.subtitle', 'S02E07 · Chikhai Bardo')
                ->missing('history.rows.0.data')));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/history?')
        && str_contains($request->url(), 'page=2&pageSize=50'));
});

test('the Radarr tab asks Radarr only', function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/history*' => Http::response(['totalRecords' => 1, 'records' => [[
            'id' => 7, 'eventType' => 'downloadFailed', 'sourceTitle' => 'Dune.2021.mkv',
            'movie' => ['title' => 'Dune', 'year' => 2021], 'date' => '2026-09-28T09:00:00Z',
        ]]]),
    ]);

    $this->actingAs(User::factory()->member()->create())
        ->get(route('media.library.activity.queue', ['history_service' => 'radarr']))
        ->assertInertia(fn ($page) => $page
            ->where('historyFilters.service', 'radarr')
            ->loadDeferredProps('history', fn ($page) => $page
                ->where('history.service', 'radarr')
                ->where('history.connection_id', $this->radarr->id)
                ->where('history.rows.0.title', 'Dune')
                ->where('history.rows.0.subtitle', '2021')));

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'sonarr.local'));
});

test('history rows never carry the indexer download link or guid', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/history*' => Http::response(['totalRecords' => 1, 'records' => [activityHistorySonarrRecord(55)]]),
    ]);

    $this->actingAs(User::factory()->member()->create())
        ->get(route('media.library.activity.queue', ['history_service' => 'sonarr']))
        ->assertInertia(fn ($page) => $page->loadDeferredProps('history', function ($page): void {
            $page->missing('history.rows.0.data');

            expect(json_encode($page->toArray(), JSON_THROW_ON_ERROR))
                ->not->toContain('indexer-secret')
                ->not->toContain('tracker-passkey');
        }));
});

test('an unreachable service renders an error, never an empty history', function (): void {
    Sleep::fake();
    Http::fake(['sonarr.local:8989/api/v3/history*' => Http::response('boom', 500)]);

    $this->actingAs(User::factory()->member()->create())
        ->get(route('media.library.activity.queue', ['history_service' => 'sonarr']))
        ->assertInertia(fn ($page) => $page->loadDeferredProps('history', fn ($page) => $page
            ->where('history.configured', true)
            ->where('history.error', 'Sonarr is unreachable right now — its history could not be loaded.')
            ->where('history.rows', [])));
});

test('a service without a connection says so', function (): void {
    $this->radarr->delete();

    $this->actingAs(User::factory()->member()->create())
        ->get(route('media.library.activity.queue', ['history_service' => 'radarr']))
        ->assertInertia(fn ($page) => $page->loadDeferredProps('history', fn ($page) => $page
            ->where('history.configured', false)
            ->where('history.connection_id', null)));

    Http::assertNothingSent();
});

test('an admin marks a grabbed history row as failed on the pinned connection', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/history/failed/55' => Http::response('', 200)]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.history.failed', ['service' => 'sonarr', 'id' => 55]), ['service_connection_id' => $this->sonarr->id])
        ->assertRedirect(route('media.library.activity.queue'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success')
        ->assertSessionHas('inertia.flash_data.toast.message', "Marked as failed — Sonarr will blocklist the release and search again if 'Redownload failed' is on.");

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/api/v3/history/failed/55'));

    $activityLog = ActivityLog::query()->where('action', 'library.history.marked_failed')->sole();

    expect($activityLog->user_id)->toBe($admin->id)
        ->and($activityLog->service_connection_id)->toBe($this->sonarr->id)
        ->and($activityLog->metadata)->toBe(['history_id' => 55]);
});

test('mark failed refuses a pin to the wrong service or a deactivated connection', function (): void {
    $admin = User::factory()->admin()->create();
    $inactiveSonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr2.local:8989', 'is_active' => false]);

    foreach ([$this->radarr->id, $inactiveSonarr->id, 999_999] as $connectionId) {
        $this->actingAs($admin)
            ->from(route('media.library.activity.queue'))
            ->post(route('media.library.activity.history.failed', ['service' => 'sonarr', 'id' => 55]), ['service_connection_id' => $connectionId])
            ->assertSessionHas('inertia.flash_data.toast.message', 'That Sonarr connection is unavailable — refresh and try again.');
    }

    Http::assertNothingSent();
    expect(ActivityLog::query()->where('action', 'library.history.marked_failed')->exists())->toBeFalse();
});

test('mark failed reports upstream refusals and outages', function (int $status, string $message): void {
    Sleep::fake();
    Http::fake(['sonarr.local:8989/api/v3/history/failed/55' => Http::response(['message' => 'Not found at /data/media?apikey=x'], $status)]);

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.history.failed', ['service' => 'sonarr', 'id' => 55]), ['service_connection_id' => $this->sonarr->id])
        ->assertSessionHas('inertia.flash_data.toast.type', 'error')
        ->assertSessionHas('inertia.flash_data.toast.message', $message);
})->with([
    'gone' => [404, 'That history entry no longer exists in Sonarr.'],
    'refused' => [400, 'Sonarr refused to mark it as failed.'],
    'down' => [503, 'Sonarr is unreachable right now.'],
]);

test('mark failed is admin-only and validates the pin', function (): void {
    $this->actingAs(User::factory()->member()->create())
        ->post(route('media.library.activity.history.failed', ['service' => 'sonarr', 'id' => 55]), ['service_connection_id' => $this->sonarr->id])
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('media.library.activity.history.failed', ['service' => 'sonarr', 'id' => 55]), [])
        ->assertSessionHasErrors('service_connection_id');

    Http::assertNothingSent();
});

test('queue removals are audited as removed or blocklisted', function (string $verb, string $action): void {
    Http::fake(['sonarr.local:8989/api/v3/queue/42*' => Http::response('', 200)]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.queue.remove', ['service' => 'sonarr', 'id' => 42]), ['verb' => $verb])
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    $activityLog = ActivityLog::query()->where('action', $action)->sole();

    expect($activityLog->isAudit())->toBeTrue()
        ->and($activityLog->user_id)->toBe($admin->id)
        ->and($activityLog->service_connection_id)->toBe($this->sonarr->id)
        ->and($activityLog->subject_type)->toBe($this->sonarr->getMorphClass())
        ->and($activityLog->subject_id)->toBe($this->sonarr->id)
        ->and($activityLog->metadata['context'])->toBe(['service' => 'sonarr', 'queue_id' => 42]);
})->with([
    'remove' => ['remove', 'queue.removed'],
    'blocklist' => ['block', 'queue.blocklisted'],
]);

test('a failed queue removal is not audited and never echoes the upstream url', function (): void {
    Sleep::fake();
    Http::fake(['sonarr.local:8989/api/v3/queue/9*' => Http::response('Server Error', 500)]);

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.queue.remove', ['service' => 'sonarr', 'id' => 9]), ['verb' => 'remove'])
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect((string) session('inertia.flash_data.toast.message'))->not->toContain('?removeFromClient')
        ->and(ActivityLog::query()->where('action', 'queue.removed')->exists())->toBeFalse();
});
