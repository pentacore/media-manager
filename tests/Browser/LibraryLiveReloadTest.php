<?php

declare(strict_types=1);

use App\Enums\WhisparrVersion;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Hands a broadcast to the page's `dashboard` channel listeners. Tests
 * broadcast through the null driver, so this emits on the pusher-js channel
 * Echo subscribed to. The layout's badges always hold `dashboard`, so a
 * missing channel throws, but only a recorded reload proves the page itself
 * listened.
 *
 * @param  array<string, mixed>  $payload
 */
function libraryLiveEmitScript(string $event, array $payload): string
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const channel = window.Pusher?.instances?.[0]?.channel('private-dashboard');
            if (!channel) {
                throw new Error('The page has not subscribed to dashboard.');
            }
            channel.emit('{$event}', {$json});
        })()
    JS;
}

/**
 * Records every partial reload the page starts, as its comma-joined `only`.
 * Install it after the deferred props have loaded.
 */
function libraryLiveRecorderScript(): string
{
    return <<<'JS'
        (() => {
            window.__mmReloads = [];
            document.addEventListener('inertia:start', (event) => {
                const visit = event.detail.visit;
                if (!visit.prefetch && visit.only.length > 0) {
                    window.__mmReloads.push(visit.only.join(','));
                }
            });
        })()
    JS;
}

function libraryLiveReloadCountScript(string $only): string
{
    return sprintf("window.__mmReloads.filter((only) => only === '%s').length", $only);
}

/**
 * Rests inside the page past useRealtimeReload's 1500 ms debounce.
 */
function libraryLiveRestScript(): string
{
    return '(async () => { await new Promise((resolve) => setTimeout(resolve, 2000)); })()';
}

/**
 * @return array<string, mixed>
 */
function libraryLiveProcessed(string $serviceType, int $serviceConnectionId): array
{
    return [
        'id' => 7001, 'service_connection_id' => $serviceConnectionId, 'service_type' => $serviceType,
        'event_type' => 'Download', 'processed_at' => '2026-10-12T10:00:00.000000Z',
    ];
}

test('the series list reloads only after a Sonarr webhook was processed', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([[
            // Longer than "Example Show": the card's broken-poster fallback
            // shows the title lower-cased and truncated to 12 characters
            // (`item.title.toLowerCase().slice(0, 12)`), which collided
            // with the shorter title and tripped Playwright's strict mode
            // on assertSeeIn (two elements contain "example show").
            'id' => 1, 'title' => 'Example Show Series', 'titleSlug' => 'example-show-series', 'year' => 2024, 'status' => 'continuing',
            'monitored' => true, 'qualityProfileId' => 1, 'images' => [], 'seasons' => [],
            'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 0, 'episodeFileCount' => 0],
        ]]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
    ]);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="1"]', 'Example Show Series');
    $webpage->script(libraryLiveRecorderScript());

    // Stored but not yet processed: the cache may still be stale.
    $webpage->script(libraryLiveEmitScript('WebhookReceived', [
        'id' => 7000, 'service_connection_id' => $sonarr->id, 'service_name' => 'Sonarr', 'service_type' => 'sonarr',
        'event_type' => 'Download', 'created_at' => '2026-10-12T10:00:00.000000Z',
    ]));
    // Processed, but for another service (a Radarr connection that is not on this page).
    $webpage->script(libraryLiveEmitScript('WebhookEventProcessed', libraryLiveProcessed('radarr', 2)));
    $webpage->script(libraryLiveRestScript());

    expect($webpage->script(libraryLiveReloadCountScript('series')))->toBe(0);

    $webpage->script(libraryLiveEmitScript('WebhookEventProcessed', libraryLiveProcessed('sonarr', $sonarr->id)));
    $webpage->script(confirmDialogWaitUntilScript(sprintf('%s > 0', libraryLiveReloadCountScript('series'))));

    expect($webpage->script(libraryLiveReloadCountScript('series')))->toBe(1);
    $webpage->assertNoSmoke();
});

test('the movie list reloads after a Radarr webhook was processed', function (): void {
    $radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    Http::fake([
        'radarr.local:7878/api/v3/movie' => Http::response([[
            'id' => 1, 'title' => 'Example Movie', 'year' => 2024, 'monitored' => true, 'hasFile' => false,
            'qualityProfileId' => 1, 'images' => [], 'sizeOnDisk' => 0,
        ]]),
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
    ]);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.movies.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-movie-card="1"]', 'Example Movie');
    $webpage->script(libraryLiveRecorderScript());

    $webpage->script(libraryLiveEmitScript('WebhookEventProcessed', libraryLiveProcessed('radarr', $radarr->id)));
    $webpage->script(confirmDialogWaitUntilScript(sprintf('%s > 0', libraryLiveReloadCountScript('movies'))));

    expect($webpage->script(libraryLiveReloadCountScript('movies')))->toBe(1);
});

test('the Seerr requests list reloads after a Seerr webhook was processed', function (): void {
    $seerr = ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake([
        'seerr.local:5055/api/v1/request/count' => Http::response(['total' => 0, 'pending' => 0, 'approved' => 0, 'declined' => 0]),
        'seerr.local:5055/api/v1/request*' => Http::response(['pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 0], 'results' => []]),
    ]);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.requests.index', absolute: false))
        ->assertNoSmoke()
        ->assertSee('No requests in this view.');
    $webpage->script(libraryLiveRecorderScript());

    $webpage->script(libraryLiveEmitScript('WebhookEventProcessed', libraryLiveProcessed('seerr', $seerr->id)));
    $webpage->script(confirmDialogWaitUntilScript(sprintf('%s > 0', libraryLiveReloadCountScript('requests,summary'))));

    expect($webpage->script(libraryLiveReloadCountScript('requests,summary')))->toBe(1);
});

test('the Whisparr library and title page reload after a Whisparr webhook was processed', function (): void {
    $whisparr = ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V3)->create(['url' => 'http://whisparr.local:6969', 'api_key' => 'k']);
    $scene = ['id' => 21, 'title' => 'Example Scene', 'year' => 2024, 'monitored' => true, 'hasFile' => false, 'qualityProfileId' => 1, 'images' => []];
    Http::fake([
        'whisparr.local:6969/api/v3/movie/21*' => Http::response($scene),
        'whisparr.local:6969/api/v3/movie' => Http::response([$scene]),
        'whisparr.local:6969/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'Any']]),
    ]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.whisparr.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-whisparr-card="21"]', 'Example Scene');
    $webpage->script(libraryLiveRecorderScript());
    $webpage->script(libraryLiveEmitScript('WebhookEventProcessed', libraryLiveProcessed('whisparr', $whisparr->id)));
    $webpage->script(confirmDialogWaitUntilScript(sprintf('%s > 0', libraryLiveReloadCountScript('library'))));

    expect($webpage->script(libraryLiveReloadCountScript('library')))->toBe(1);

    $title = visit(route('media.whisparr.show', ['id' => 21], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-whisparr-show-title]', 'Example Scene');
    $title->script(libraryLiveRecorderScript());
    $title->script(libraryLiveEmitScript('WebhookEventProcessed', libraryLiveProcessed('whisparr', $whisparr->id)));
    $title->script(confirmDialogWaitUntilScript(sprintf('%s > 0', libraryLiveReloadCountScript('item,scenes'))));

    expect($title->script(libraryLiveReloadCountScript('item,scenes')))->toBe(1);
});
