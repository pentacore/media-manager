<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Confirms a manual movie delete from the show page goes through the action
 * pipeline (approval queued) rather than calling Radarr directly, per
 * SD-2026-09-27 task 1. Fixture shapes follow tests/Browser/MediaReplacementDialogTest.php.
 */
test('member queues a movie delete for approval from the show page', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'delete_movie',
        'requires_approval' => true,
        'is_enabled' => true,
    ]);

    $radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);

    Http::fake([
        'radarr.local:7878/api/v3/qualityprofile*' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'radarr.local:7878/api/v3/movie/10' => Http::response([
            'id' => 10,
            'title' => 'A Movie',
            'titleSlug' => 'a-movie-2026',
            'year' => 2026,
            'status' => 'released',
            'monitored' => true,
            'hasFile' => true,
            'qualityProfileId' => 1,
            'sizeOnDisk' => 5_000_000_000,
            'images' => [],
            'overview' => 'A movie about a thing.',
            'runtime' => 120,
            'studio' => 'Example Studio',
            'rootFolderPath' => '/movies',
        ]),
    ]);

    $this->actingAs(User::factory()->member()->create());

    visit(route('media.movies.show', ['id' => 10], absolute: false))
        ->assertSee('A Movie')
        ->assertNoSmoke()
        ->click('[data-delete-trigger]')
        ->assertSeeIn('[data-delete-description]', 'approval in the')
        ->click('[data-delete-confirm]')
        ->assertSee('Deletion queued for approval in the Action Queue.')
        ->assertNoSmoke();

    Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE');

    $actionRequest = ActionRequest::query()->where('type', 'delete_movie')->sole();
    expect($actionRequest->origin)->toBe('manual');
    expect($actionRequest->status)->toBe(ActionRequestStatus::Pending);
    expect($actionRequest->payload)->toMatchArray([
        'radarr_movie_id' => 10,
        'delete_files' => false,
        'service_connection_id' => $radarr->id,
    ]);
});

/**
 * Holds every DELETE XHR in the page so the delete visit stays in flight, and
 * counts how many were sent. Inertia v3 issues visits through XMLHttpRequest.
 */
function manualDeleteHoldDeleteRequestsScript(): string
{
    return <<<'JS'
        () => {
            window.__deleteRequests = 0;
            const open = XMLHttpRequest.prototype.open;
            XMLHttpRequest.prototype.open = function (method, ...rest) {
                this.__isDelete = String(method).toUpperCase() === 'DELETE';
                return open.call(this, method, ...rest);
            };
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.send = function (...args) {
                if (this.__isDelete) {
                    window.__deleteRequests++;
                    return;
                }
                return send.apply(this, args);
            };
        }
        JS;
}

/**
 * Two synchronous clicks, as a fast double-click lands before Vue re-renders.
 */
function manualDeleteDoubleClickConfirmScript(): string
{
    return <<<'JS'
        () => {
            const button = document.querySelector('[data-delete-confirm]');
            button.click();
            button.click();
        }
        JS;
}

test('a double-clicked movie delete confirm sends one request and stays disabled while it is in flight', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'delete_movie',
        'requires_approval' => true,
        'is_enabled' => true,
    ]);

    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);

    Http::fake([
        'radarr.local:7878/api/v3/qualityprofile*' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'radarr.local:7878/api/v3/movie/10' => Http::response([
            'id' => 10,
            'title' => 'A Movie',
            'titleSlug' => 'a-movie-2026',
            'year' => 2026,
            'status' => 'released',
            'monitored' => true,
            'hasFile' => true,
            'qualityProfileId' => 1,
            'sizeOnDisk' => 5_000_000_000,
            'images' => [],
            'overview' => 'A movie about a thing.',
            'runtime' => 120,
            'studio' => 'Example Studio',
            'rootFolderPath' => '/movies',
        ]),
    ]);

    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.movies.show', ['id' => 10], absolute: false))
        ->assertSee('A Movie')
        ->assertNoSmoke()
        ->click('[data-delete-trigger]')
        ->assertEnabled('[data-delete-confirm]');

    $webpage->script(manualDeleteHoldDeleteRequestsScript());
    $webpage->script(manualDeleteDoubleClickConfirmScript());

    $webpage->assertDisabled('[data-delete-confirm]')
        ->assertScript('window.__deleteRequests', 1);
});

test('a double-clicked series delete confirm sends one request and stays disabled while it is in flight', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'delete_series',
        'requires_approval' => true,
        'is_enabled' => true,
    ]);

    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);

    Http::fake([
        'sonarr.local:8989/api/v3/qualityprofile*' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'sonarr.local:8989/api/v3/series/20' => Http::response([
            'id' => 20,
            'title' => 'A Show',
            'titleSlug' => 'a-show',
            'year' => 2026,
            'status' => 'continuing',
            'monitored' => true,
            'qualityProfileId' => 1,
            'images' => [],
            'seasons' => [],
            'overview' => 'A show about a thing.',
            'statistics' => ['sizeOnDisk' => 0, 'episodeFileCount' => 0, 'episodeCount' => 0],
        ]),
        'sonarr.local:8989/api/v3/episode*' => Http::response([]),
    ]);

    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.show', ['id' => 20], absolute: false))
        ->assertSee('A Show')
        ->assertNoSmoke()
        ->click('[data-delete-trigger]')
        ->assertEnabled('[data-delete-confirm]');

    $webpage->script(manualDeleteHoldDeleteRequestsScript());
    $webpage->script(manualDeleteDoubleClickConfirmScript());

    $webpage->assertDisabled('[data-delete-confirm]')
        ->assertScript('window.__deleteRequests', 1);
});

test('member queues a series delete pinned to the connection the page came from', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'delete_series',
        'requires_approval' => true,
        'is_enabled' => true,
    ]);

    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);

    Http::fake([
        'sonarr.local:8989/api/v3/qualityprofile*' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'sonarr.local:8989/api/v3/series/20' => Http::response([
            'id' => 20,
            'title' => 'A Show',
            'titleSlug' => 'a-show',
            'year' => 2026,
            'status' => 'continuing',
            'monitored' => true,
            'qualityProfileId' => 1,
            'images' => [],
            'seasons' => [],
            'overview' => 'A show about a thing.',
            'statistics' => ['sizeOnDisk' => 0, 'episodeFileCount' => 0, 'episodeCount' => 0],
        ]),
        'sonarr.local:8989/api/v3/episode*' => Http::response([]),
    ]);

    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.show', ['id' => 20], absolute: false))
        ->assertSee('A Show')
        ->assertNoSmoke()
        ->click('[data-delete-trigger]')
        ->click('[data-delete-confirm]')
        ->assertSee('Deletion queued for approval in the Action Queue.')
        ->assertNoSmoke();

    Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE');

    expect(ActionRequest::query()->where('type', 'delete_series')->sole()->payload)->toMatchArray([
        'sonarr_series_id' => 20,
        'delete_files' => false,
        'service_connection_id' => $sonarr->id,
    ]);
});
