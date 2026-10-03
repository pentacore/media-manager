<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
});

test('guests are redirected to login from the activity queue', function (): void {
    $this->get(route('media.library.activity.queue'))
        ->assertRedirect(route('login'));
});

test('viewers cannot access the activity queue', function (): void {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->get(route('media.library.activity.queue'))
        ->assertForbidden();
});

test('combined queue merges Sonarr and Radarr records and tags them by service', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'sonarr-key',
    ]);
    $radarr = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'api_key' => 'radarr-key',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => Http::response([
            'records' => [
                [
                    'id' => 1,
                    'status' => 'downloading',
                    'trackedDownloadStatus' => 'ok',
                    'trackedDownloadState' => 'downloading',
                    'series' => ['title' => 'Severance'],
                    'episode' => ['seasonNumber' => 2, 'episodeNumber' => 1, 'title' => 'Hello, Ms. Cobel'],
                    'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
                    'size' => 1_000_000_000,
                    'sizeleft' => 250_000_000,
                    'timeleft' => '00:10:00',
                    'added' => '2026-04-29T10:00:00Z',
                ],
            ],
        ]),
        'radarr.local:7878/api/v3/queue*' => Http::response([
            'records' => [
                [
                    'id' => 99,
                    'status' => 'queued',
                    'trackedDownloadStatus' => 'warning',
                    'trackedDownloadState' => 'importBlocked',
                    'movie' => ['title' => 'Dune', 'year' => 2021],
                    'quality' => ['quality' => ['name' => 'Bluray-1080p']],
                    'size' => 5_000_000_000,
                    'sizeleft' => 0,
                    'timeleft' => null,
                    'added' => '2026-04-29T11:00:00Z',
                    'errorMessage' => 'Sample folder is not allowed',
                ],
            ],
        ]),
        // Queue tests don't care about history but the controller defers
        // both, and `loadDeferredProps('default')` triggers everything.
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
        'radarr.local:7878/api/v3/history*' => Http::response(['records' => []]),
    ]);

    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.library.activity.queue'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library/Activity')
            ->loadDeferredProps('default', function ($page) use ($sonarr, $radarr): void {
                $page
                    ->where('queue.services.sonarr', true)
                    ->where('queue.services.radarr', true)
                    ->has('queue.rows', 2)
                    // Latest-added first.
                    ->where('queue.rows.0.service', 'radarr')
                    ->where('queue.rows.0.service_connection_id', $radarr->id)
                    ->where('queue.rows.0.title', 'Dune')
                    ->where('queue.rows.0.error_message', 'Sample folder is not allowed')
                    ->where('queue.rows.1.service', 'sonarr')
                    ->where('queue.rows.1.service_connection_id', $sonarr->id)
                    ->where('queue.rows.1.title', 'Severance')
                    ->where('queue.rows.1.subtitle', 'S02E01 · Hello, Ms. Cobel')
                    ->where('queue.errors', []);
            })
        );
});

test('queue surfaces errors per service when an upstream call fails', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => Http::response('Server Error', 500),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
    ]);

    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.library.activity.queue'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', function ($page): void {
                $page
                    ->where('queue.services.sonarr', true)
                    ->where('queue.services.radarr', false)
                    ->has('queue.rows', 0)
                    ->has('queue.errors', 1);
            })
        );
});

test('admin can force-grab a delayed Sonarr queue item', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/queue/grab/55' => Http::response(['id' => 55, 'status' => 'downloading'], 200),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.queue.grab', ['service' => 'sonarr', 'id' => 55]))
        ->assertRedirect(route('media.library.activity.queue'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/api/v3/queue/grab/55')
    );
});

test('member cannot force-grab a queue item', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->post(route('media.library.activity.queue.grab', ['service' => 'sonarr', 'id' => 55]))
        ->assertForbidden();
});

test('admin can remove a Sonarr queue item without blocklisting', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/queue/42*' => Http::response('', 200),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.queue.remove', ['service' => 'sonarr', 'id' => 42]), ['verb' => 'remove', 'service_connection_id' => $connection->id])
        ->assertRedirect(route('media.library.activity.queue'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains((string) $request->url(), '/api/v3/queue/42')
        && str_contains((string) $request->url(), 'blocklist=false')
        && str_contains((string) $request->url(), 'skipRedownload=true')
    );
});

test('admin can blocklist and re-search a Radarr queue item', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
    ]);

    Http::fake([
        'radarr.local:7878/api/v3/queue/77*' => Http::response('', 200),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.queue.remove', ['service' => 'radarr', 'id' => 77]), ['verb' => 'block', 'service_connection_id' => $connection->id])
        ->assertRedirect(route('media.library.activity.queue'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains((string) $request->url(), '/api/v3/queue/77')
        && str_contains((string) $request->url(), 'blocklist=true')
        && str_contains((string) $request->url(), 'skipRedownload=false')
    );
});

test('member cannot remove a queue item', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->post(route('media.library.activity.queue.remove', ['service' => 'sonarr', 'id' => 1]), ['verb' => 'remove'])
        ->assertForbidden();
});

test('queue removal rejects an unknown verb', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.queue.remove', ['service' => 'sonarr', 'id' => 1]), ['verb' => 'nuke', 'service_connection_id' => $connection->id])
        ->assertRedirect(route('media.library.activity.queue'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');
});

test('queue removal reports upstream HTTP failure', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/queue/9*' => Http::response('Server Error', 500),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.queue.remove', ['service' => 'sonarr', 'id' => 9]), ['verb' => 'remove', 'service_connection_id' => $connection->id])
        ->assertRedirect(route('media.library.activity.queue'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');
});

test('a queue removal acts on the connection the rows came from, not the active one', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    $secondSonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr-4k.local:8989']);
    Http::fake(['sonarr-4k.local:8989/api/v3/queue/42*' => Http::response('', 200)]);

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.queue.remove', ['service' => 'sonarr', 'id' => 42]), ['verb' => 'remove', 'service_connection_id' => $secondSonarr->id])
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && str_contains((string) $request->url(), 'sonarr-4k.local:8989/api/v3/queue/42'));
    Http::assertSentCount(1);

    $activityLog = ActivityLog::query()->where('category', 'audit')->where('action', 'queue.removed')->sole();
    expect($activityLog->subject_type)->toBe(ServiceConnection::class)
        ->and($activityLog->subject_id)->toBe($secondSonarr->id);
});

test('a queue removal refuses a pin that is gone, deactivated or another service and sends nothing', function (int $pinnedConnectionId): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    Http::fake(['sonarr.local:8989/api/v3/queue/*' => Http::response('', 200)]);

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.queue.remove', ['service' => 'sonarr', 'id' => 42]), ['verb' => 'remove', 'service_connection_id' => $pinnedConnectionId])
        ->assertRedirect(route('media.library.activity.queue'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error')
        ->assertSessionHas('inertia.flash_data.toast.message', 'That Sonarr connection is unavailable — refresh and try again.');

    Http::assertNothingSent();
})->with([
    'deleted' => [fn (): int => 999_999],
    'deactivated' => [fn (): int => ServiceConnection::factory()->sonarr()->inactive()->create(['url' => 'http://sonarr-old.local:8989'])->id],
    'another service' => [fn (): int => ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878'])->id],
]);

test('a queue removal without a pin is a validation error', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    Http::fake(['sonarr.local:8989/api/v3/queue/*' => Http::response('', 200)]);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('media.library.activity.queue.remove', ['service' => 'sonarr', 'id' => 42]), ['verb' => 'remove'])
        ->assertSessionHasErrors('service_connection_id');

    Http::assertNothingSent();
});

test('admin can list manual import candidates for a Sonarr download', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/manualimport*' => Http::response([
            [
                'path' => '/downloads/Show.S01E01.mkv',
                'name' => 'Show.S01E01',
                'size' => 1_000_000_000,
                'series' => ['id' => 12, 'title' => 'Severance'],
                'episodes' => [
                    ['id' => 555, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Pilot'],
                ],
                'quality' => ['quality' => ['id' => 4, 'name' => 'WEBDL-1080p']],
                'languages' => [['id' => 1, 'name' => 'English']],
                'releaseGroup' => 'GROUP',
                'releaseType' => 'singleEpisode',
                'rejections' => [],
            ],
        ]),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->getJson(route('media.library.activity.manual-import.candidates', [
            'service' => 'sonarr',
            'downloadId' => 'ABC123',
        ]))
        ->assertOk()
        ->assertJsonPath('candidates.0.series_title', 'Severance')
        ->assertJsonPath('candidates.0.quality', 'WEBDL-1080p')
        ->assertJsonPath('candidates.0.episodes.0.episode', 1);
});

test('admin can execute a Sonarr manual import end-to-end', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/manualimport*' => Http::response([
            [
                'path' => '/downloads/Show.S01E01.mkv',
                'series' => ['id' => 12, 'title' => 'Severance'],
                'episodes' => [['id' => 555, 'seasonNumber' => 1, 'episodeNumber' => 1]],
                'quality' => ['quality' => ['id' => 4]],
                'languages' => [['id' => 1]],
                'releaseGroup' => 'GROUP',
                'releaseType' => 'singleEpisode',
                'rejections' => [],
            ],
        ]),
        'sonarr.local:8989/api/v3/command' => Http::response(['id' => 99], 200),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.manual-import.execute', ['service' => 'sonarr']), [
            'download_id' => 'ABC123',
        ])
        ->assertRedirect(route('media.library.activity.queue'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/api/v3/command')
        && $request->data()['name'] === 'ManualImport'
        && ($request->data()['files'][0]['seriesId'] ?? null) === 12
        && ($request->data()['files'][0]['episodeIds'] ?? []) === [555]
        && ($request->data()['files'][0]['downloadId'] ?? null) === 'ABC123'
    );
});

test('manual import drops candidates without a foreign key', function (): void {
    ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
    ]);

    Http::fake([
        'radarr.local:7878/api/v3/manualimport*' => Http::response([
            // Missing movie.id — should be skipped.
            [
                'path' => '/downloads/Mystery.mkv',
                'quality' => ['quality' => ['id' => 1]],
                'languages' => [['id' => 1]],
                'rejections' => [],
            ],
        ]),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.manual-import.execute', ['service' => 'radarr']), [
            'download_id' => 'XYZ',
        ])
        ->assertRedirect(route('media.library.activity.queue'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    // Command endpoint should never be hit when there's nothing valid to send.
    Http::assertNotSent(fn ($request): bool => str_ends_with((string) $request->url(), '/api/v3/command'));
});

test('member cannot trigger manual import', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->post(route('media.library.activity.manual-import.execute', ['service' => 'sonarr']), [
            'download_id' => 'ABC',
        ])
        ->assertForbidden();
});

test('history shows the Sonarr tab by default and a Radarr tab on request', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'sonarr-key']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'radarr-key']);

    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => Http::response(['records' => []]),
        'radarr.local:7878/api/v3/queue*' => Http::response(['records' => []]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['totalRecords' => 1, 'records' => [[
            'id' => 1, 'eventType' => 'grabbed', 'sourceTitle' => 'Severance.S01E01.WEBDL-1080p.mkv',
            'series' => ['title' => 'Severance'], 'episode' => ['seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Pilot'],
            'quality' => ['quality' => ['name' => 'WEBDL-1080p']], 'date' => '2026-04-30T08:00:00Z',
        ]]]),
        'radarr.local:7878/api/v3/history*' => Http::response(['totalRecords' => 1, 'records' => [[
            'id' => 99, 'eventType' => 'downloadFailed', 'sourceTitle' => 'Dune.2021.Bluray-1080p.mkv',
            'movie' => ['title' => 'Dune', 'year' => 2021], 'quality' => ['quality' => ['name' => 'Bluray-1080p']], 'date' => '2026-04-30T09:00:00Z',
        ]]]),
    ]);

    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.library.activity.queue'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library/Activity')
            ->where('historyFilters', ['service' => 'sonarr', 'page' => 1, 'active' => false])
            ->loadDeferredProps('history', fn ($page) => $page
                ->has('history.rows', 1)
                ->where('history.rows.0.service', 'sonarr')
                ->where('history.rows.0.subtitle', 'S01E01 · Pilot')));

    $this->actingAs($member)
        ->get(route('media.library.activity.queue', ['history_service' => 'radarr']))
        ->assertInertia(fn ($page) => $page->loadDeferredProps('history', fn ($page) => $page
            ->has('history.rows', 1)
            ->where('history.rows.0.service', 'radarr')
            ->where('history.rows.0.event_type', 'downloadFailed')));
});

test('queue is empty when no Sonarr or Radarr connection is configured', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.library.activity.queue'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', function ($page): void {
                $page
                    ->where('queue.services.sonarr', false)
                    ->where('queue.services.radarr', false)
                    ->has('queue.rows', 0)
                    ->where('queue.errors', []);
            })
        );
});

test('a failed queue load names the service without echoing the upstream response', function (): void {
    Sleep::fake();
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);

    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => Http::response('Error reading /config/sonarr.db?apikey=sonarr-secret', 500),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
    ]);

    $this->actingAs(User::factory()->member()->create())
        ->get(route('media.library.activity.queue'))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', fn ($page) => $page
                ->where('queue.errors', ['Sonarr is unreachable right now — its queue could not be loaded.'])));
});

test('a Sonarr queue behind a login page is reported as an outage, not an empty queue', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake(['sonarr.local:8989/*' => Http::response('<html><body>Sign in</body></html>', 200, ['Content-Type' => 'text/html'])]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('media.library.activity.queue'))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', fn ($reload) => $reload
                ->where('queue.rows', [])
                ->where('queue.errors', ['Sonarr is unreachable right now — its queue could not be loaded.'])));
});

test('queue status messages and error text keep their words but lose paths', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);

    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => Http::response(['records' => [[
            'id' => 3,
            'status' => 'completed',
            'trackedDownloadState' => 'importBlocked',
            'series' => ['title' => 'Severance'],
            'added' => '2026-09-30T10:00:00Z',
            'errorMessage' => 'Import failed at /downloads/complete/Severance.S02E01/file.mkv',
            'statusMessages' => [[
                'title' => '/downloads/complete/Severance.S02E01/file.mkv',
                'messages' => ['No files found are eligible for import in /downloads/complete/Severance.S02E01', '   '],
            ]],
        ]]]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
    ]);

    $this->actingAs(User::factory()->member()->create())
        ->get(route('media.library.activity.queue'))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', fn ($page) => $page
                ->where('queue.rows.0.error_message', 'Import failed at [redacted path]')
                ->where('queue.rows.0.status_messages', [[
                    'title' => '[redacted path]',
                    'messages' => ['No files found are eligible for import in [redacted path]'],
                ]])));
});

test('malformed queue status messages render as an empty or trimmed list, never a 500', function (mixed $statusMessages, array $expected): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);

    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => Http::response(['records' => [[
            'id' => 4,
            'series' => ['title' => 'Severance'],
            'added' => '2026-09-30T10:00:00Z',
            'errorMessage' => '  ',
            'statusMessages' => $statusMessages,
        ]]]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
    ]);

    $this->actingAs(User::factory()->member()->create())
        ->get(route('media.library.activity.queue'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', fn ($page) => $page
                ->where('queue.rows.0.error_message', null)
                ->where('queue.rows.0.status_messages', $expected)));
})->with([
    'a string' => ['oops', []],
    'junk entries' => [[['title' => null, 'messages' => 'x'], 'junk', ['title' => 'Sample', 'messages' => [12, '', 'Not an upgrade']]], [
        ['title' => '', 'messages' => []],
        ['title' => 'Sample', 'messages' => ['Not an upgrade']],
    ]],
]);

test('a refused force grab reports the reason without paths', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    Http::fake(['sonarr.local:8989/api/v3/queue/grab/55' => Http::response('Cannot read /data/torrents/Severance.S02E01', 400)]);

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.queue.grab', ['service' => 'sonarr', 'id' => 55]))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect((string) session('inertia.flash_data.toast.message'))
        ->toStartWith('Force grab failed:')
        ->toContain('[redacted path]')
        ->not->toContain('/data/torrents');
});

test('manual import failures never echo upstream paths', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    Http::fake(['sonarr.local:8989/api/v3/manualimport*' => Http::response('Folder /downloads/complete/ABC123 is not readable', 400)]);
    $admin = User::factory()->admin()->create();

    $json = $this->actingAs($admin)
        ->getJson(route('media.library.activity.manual-import.candidates', ['service' => 'sonarr', 'downloadId' => 'ABC123']))
        ->assertStatus(502)
        ->json('error');

    expect($json)->toContain('[redacted path]')->not->toContain('/downloads/complete');

    $this->actingAs($admin)
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.manual-import.execute', ['service' => 'sonarr']), ['download_id' => 'ABC123'])
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect((string) session('inertia.flash_data.toast.message'))
        ->toStartWith('Could not enumerate import candidates:')
        ->not->toContain('/downloads/complete');
});

test('a failed manual import command never echoes upstream paths', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    Http::fake([
        'sonarr.local:8989/api/v3/manualimport*' => Http::response([[
            'path' => '/downloads/Show.S01E01.mkv',
            'series' => ['id' => 12, 'title' => 'Severance'],
            'episodes' => [['id' => 555, 'seasonNumber' => 1, 'episodeNumber' => 1]],
            'quality' => ['quality' => ['id' => 4]],
            'languages' => [['id' => 1]],
            'rejections' => [],
        ]]),
        'sonarr.local:8989/api/v3/command' => Http::response('Cannot move /downloads/Show.S01E01.mkv to /tv/Severance', 400),
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.manual-import.execute', ['service' => 'sonarr']), ['download_id' => 'ABC123'])
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect((string) session('inertia.flash_data.toast.message'))
        ->toStartWith('Manual import failed:')
        ->not->toContain('/downloads/')
        ->not->toContain('/tv/');
});
