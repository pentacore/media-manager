<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

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
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'sonarr-key',
    ]);
    ServiceConnection::factory()->radarr()->create([
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
    ]);

    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.library.activity.queue'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library/Activity')
            ->loadDeferredProps('default', function ($page): void {
                $page
                    ->where('queue.services.sonarr', true)
                    ->where('queue.services.radarr', true)
                    ->has('queue.rows', 2)
                    // Latest-added first.
                    ->where('queue.rows.0.service', 'radarr')
                    ->where('queue.rows.0.title', 'Dune')
                    ->where('queue.rows.0.error_message', 'Sample folder is not allowed')
                    ->where('queue.rows.1.service', 'sonarr')
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

test('admin can remove a Sonarr queue item without blocklisting', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/queue/42*' => Http::response('', 200),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.queue.remove', ['service' => 'sonarr', 'id' => 42]), ['verb' => 'remove'])
        ->assertRedirect(route('media.library.activity.queue'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains((string) $request->url(), '/api/v3/queue/42')
        && str_contains((string) $request->url(), 'blocklist=false')
        && str_contains((string) $request->url(), 'skipRedownload=true')
    );
});

test('admin can blocklist and re-search a Radarr queue item', function (): void {
    ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
    ]);

    Http::fake([
        'radarr.local:7878/api/v3/queue/77*' => Http::response('', 200),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.queue.remove', ['service' => 'radarr', 'id' => 77]), ['verb' => 'block'])
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
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.queue.remove', ['service' => 'sonarr', 'id' => 1]), ['verb' => 'nuke'])
        ->assertRedirect(route('media.library.activity.queue'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');
});

test('queue removal reports upstream HTTP failure', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/queue/9*' => Http::response('Server Error', 500),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('media.library.activity.queue'))
        ->post(route('media.library.activity.queue.remove', ['service' => 'sonarr', 'id' => 9]), ['verb' => 'remove'])
        ->assertRedirect(route('media.library.activity.queue'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');
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
