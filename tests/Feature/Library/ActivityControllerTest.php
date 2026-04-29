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
