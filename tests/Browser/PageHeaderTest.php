<?php

declare(strict_types=1);

use App\Cache\Services\SeerrCache;
use App\Enums\ActionRequestStatus;
use App\Enums\WhisparrVersion;
use App\Jobs\ClearSeerrRequests;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * @return array<int, array<string, string>>
 */
function pageHeaderPoster(): array
{
    return [['coverType' => 'poster', 'remoteUrl' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7']];
}

/**
 * @return array<string, mixed>
 */
function pageHeaderSeries(int $id, string $title): array
{
    return [
        'id' => $id, 'title' => $title, 'titleSlug' => sprintf('example-%d', $id), 'year' => 2024, 'status' => 'continuing',
        'monitored' => true, 'qualityProfileId' => 1, 'images' => pageHeaderPoster(), 'seasons' => [],
        'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 0, 'episodeFileCount' => 0],
    ];
}

/**
 * One stateful Sonarr fake: the series list grows after the first read, so a
 * Sync is visible, and $state counts the reads.
 */
function pageHeaderFakeSonarr(object $state): void
{
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake([
        'sonarr.local:8989/api/v3/series' => function () use ($state) {
            $state->calls++;

            return Http::response($state->calls === 1
                ? [pageHeaderSeries(1, 'Example Show')]
                : [pageHeaderSeries(1, 'Example Show'), pageHeaderSeries(2, 'Example Sequel')]);
        },
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'sonarr.local:8989/*' => Http::response([]),
    ]);
}

/**
 * One stateful Seerr fake: the pending count grows after the first read, so a
 * Sync that re-reads it is visible, and $state counts the reads.
 */
function pageHeaderFakeSeerr(object $state): ServiceConnection
{
    $connection = ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake([
        'seerr.local:5055/api/v1/request/count' => function () use ($state) {
            $state->countCalls++;
            $pending = $state->countCalls === 1 ? 1 : 2;

            return Http::response(['total' => $pending, 'pending' => $pending, 'approved' => 0, 'declined' => 0, 'movie' => $pending, 'tv' => 0]);
        },
        'seerr.local:5055/api/v1/request*' => Http::response(['pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 1], 'results' => [
            ['id' => 1, 'status' => 1, 'type' => 'movie', 'media' => ['mediaType' => 'movie', 'tmdbId' => 9001, 'status' => 3], 'requestedBy' => ['displayName' => 'Example User', 'email' => 'user@example.com'], 'createdAt' => '2026-10-01T00:00:00Z', 'seasons' => []],
        ]]),
        'seerr.local:5055/api/v1/movie/*' => Http::response(['id' => 9001, 'title' => 'Example Movie', 'posterPath' => '/example.jpg']),
        'seerr.local:5055/*' => Http::response([]),
    ]);

    return $connection;
}

function pageHeaderInViewportScript(string $selector): string
{
    $encoded = json_encode($selector, JSON_THROW_ON_ERROR);

    return "(() => { const element = document.querySelector({$encoded}); if (!element) { return false; } const rect = element.getBoundingClientRect(); return rect.width > 0 && rect.left >= 0 && rect.right <= window.innerWidth; })()";
}

test('at phone width the series header keeps Add series on screen and moves the rest into the menu', function (): void {
    pageHeaderFakeSonarr(new class
    {
        public int $calls = 0;
    });
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertVisible('[data-add-series]')
        ->assertScript(pageHeaderInViewportScript('[data-add-series]'), true)
        ->assertScript(pageHeaderInViewportScript('[data-page-header-more]'), true)
        ->assertMissing('[data-library-sync]')
        ->assertScript(phoneOverflowScript('[data-page-header]'), '')
        ->click('[data-page-header-more]')
        ->assertSeeIn('[data-page-header-menu] [data-page-header-item="sync"]', 'Sync')
        ->assertSeeIn('[data-page-header-menu] [data-page-header-item="activity"]', 'Activity')
        ->assertSeeIn('[data-page-header-menu] [data-page-header-item="open-sonarr"]', 'Open Sonarr')
        ->assertAttribute('[data-page-header-item="open-sonarr"]', 'href', 'http://sonarr.local:8989')
        ->assertAttribute('[data-page-header-item="open-sonarr"]', 'target', '_blank');
});

test('Sync from the phone menu reads Sonarr again', function (): void {
    $state = new class
    {
        public int $calls = 0;
    };
    pageHeaderFakeSonarr($state);
    $this->actingAs(User::factory()->create());

    $webpage = visit(route('media.series.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertSeeIn('[data-series-card="1"]', 'Example Show')
        ->click('[data-page-header-more]')
        ->click('[data-page-header-item="sync"]')
        ->assertSee('Synced with Sonarr.')
        ->assertSeeIn('[data-series-card="2"]', 'Example Sequel');

    expect($state->calls)->toBe(2);
});

test("a viewer's series header has no primary action and a menu with only Sync", function (): void {
    pageHeaderFakeSonarr(new class
    {
        public int $calls = 0;
    });
    $this->actingAs(User::factory()->create());

    $webpage = visit(route('media.series.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertNotPresent('[data-add-series]')
        ->click('[data-page-header-more]')
        ->assertCount('[data-page-header-menu] [data-page-header-item]', 1)
        ->assertSeeIn('[data-page-header-menu] [data-page-header-item="sync"]', 'Sync');
});

test('the overflow menu works from the keyboard and returns focus to its trigger', function (): void {
    pageHeaderFakeSonarr(new class
    {
        public int $calls = 0;
    });
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertAttribute('[data-page-header-more]', 'aria-label', 'More actions')
        ->keys('[data-page-header-more]', 'Enter');
    $webpage->script(confirmDialogWaitUntilScript('document.activeElement?.getAttribute("data-page-header-item") === "sync"'));
    $webpage->assertScript('document.activeElement?.getAttribute("data-page-header-item")', 'sync')
        ->keys('[data-page-header-item="sync"]', 'ArrowDown');
    $webpage->assertScript('document.activeElement?.getAttribute("data-page-header-item")', 'activity')
        ->keys('[data-page-header-item="activity"]', 'Escape');
    $webpage->script(confirmDialogWaitUntilScript('!document.querySelector("[data-page-header-menu]")'));
    $webpage->assertNotPresent('[data-page-header-menu]')
        ->assertScript('document.activeElement === document.querySelector("[data-page-header-more]")', true)
        ->keys('[data-page-header-more]', 'Enter');
    $webpage->script(confirmDialogWaitUntilScript('document.activeElement?.getAttribute("data-page-header-item") === "sync"'));
    $webpage->keys('[data-page-header-item="sync"]', 'Enter');
    $webpage->assertSee('Synced with Sonarr.');
});

test('at desktop width the header shows every action inline and no menu trigger', function (): void {
    pageHeaderFakeSonarr(new class
    {
        public int $calls = 0;
    });
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-library-sync]')
        ->assertVisible('[data-add-series]')
        ->assertMissing('[data-page-header-more]');
});

test('the movie and Whisparr headers keep their main action on a phone', function (): void {
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V3)->create(['url' => 'http://whisparr.local:6969', 'api_key' => 'k']);
    Http::fake([
        'radarr.local:7878/api/v3/movie*' => Http::response([]),
        'radarr.local:7878/*' => Http::response([]),
        'whisparr.local:6969/api/v3/movie*' => Http::response([]),
        'whisparr.local:6969/*' => Http::response([]),
    ]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.movies.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertScript(pageHeaderInViewportScript('[data-add-movie]'), true)
        ->assertScript(phoneOverflowScript('[data-page-header]'), '')
        ->click('[data-page-header-more]')
        ->assertSeeIn('[data-page-header-menu] [data-page-header-item="activity"]', 'Activity')
        ->assertAttribute('[data-page-header-item="activity"]', 'href', 'http://radarr.local:7878/activity/queue')
        ->assertAttribute('[data-page-header-item="activity"]', 'target', '_blank')
        ->assertSeeIn('[data-page-header-menu] [data-page-header-item="open-radarr"]', 'Open Radarr')
        ->assertAttribute('[data-page-header-item="open-radarr"]', 'href', 'http://radarr.local:7878')
        ->assertAttribute('[data-page-header-item="open-radarr"]', 'target', '_blank');

    $webpage->navigate(route('media.whisparr.index', absolute: false));
    $webpage->script(appHydratedScript());
    $webpage->assertScript(pageHeaderInViewportScript('[data-library-sync]'), true)
        ->click('[data-page-header-more]')
        ->assertSeeIn('[data-page-header-menu] [data-page-header-item="open-whisparr"]', 'Open Whisparr')
        ->assertAttribute('[data-page-header-item="open-whisparr"]', 'href', 'http://whisparr.local:6969')
        ->assertAttribute('[data-page-header-item="open-whisparr"]', 'target', '_blank');
});

test("an admin's phone requests header keeps the user filter and puts Sync, the bulk deletes and Open Seerr in the menu", function (): void {
    $state = new class
    {
        public int $countCalls = 0;
    };
    $serviceConnection = pageHeaderFakeSeerr($state);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertScript(phoneOverflowScript('[data-page-header]'), '')
        ->assertVisible('[data-page-header-filters]')
        ->assertScript(pageHeaderInViewportScript('[data-page-header-filters] button'), true)
        ->assertSeeIn('[data-page-header]', '1 pending')
        ->click('[data-page-header-more]')
        ->assertSeeIn('[data-page-header-menu]', 'Bulk delete')
        ->assertPresent('[data-page-header-menu] [data-page-header-item="clear-declined"]')
        ->assertSeeIn('[data-page-header-menu] [data-page-header-item="open-seerr"]', 'Open Seerr')
        ->assertAttribute('[data-page-header-item="open-seerr"]', 'href', 'http://seerr.local:5055')
        ->assertAttribute('[data-page-header-item="open-seerr"]', 'target', '_blank')
        ->assertSeeIn('[data-page-header-menu] [data-page-header-item="sync"]', 'Sync Seerr');

    new SeerrCache($serviceConnection)->bustAll();
    $webpage->click('[data-page-header-item="sync"]')
        ->assertSeeIn('[data-page-header]', '2 pending');

    expect($state->countCalls)->toBe(2);
});

test('a bulk delete from the phone menu asks first; Cancel queues nothing and the confirm queues the clear', function (): void {
    Queue::fake([ClearSeerrRequests::class]);
    pageHeaderFakeSeerr(new class
    {
        public int $countCalls = 0;
    });
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-page-header-more]')
        ->click('[data-page-header-item="clear-failed"]')
        ->assertSeeIn('[data-confirm-dialog]', 'Permanently delete every failed Seerr request?')
        ->click('[data-confirm-cancel]');

    $webpage->script(confirmDialogGoneScript());
    Queue::assertNotPushed(ClearSeerrRequests::class);

    $webpage->click('[data-page-header-more]')
        ->click('[data-page-header-item="clear-failed"]')
        ->assertSeeIn('[data-confirm-dialog]', 'Permanently delete every failed Seerr request?')
        ->click('[data-confirm-accept]')
        ->assertSee('in the background')
        ->assertNoSmoke();

    Queue::assertPushed(ClearSeerrRequests::class);
});

test("a member's phone dashboard keeps Review queue on screen and Refresh in the menu", function (): void {
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertScript(pageHeaderInViewportScript('[data-dashboard-review-queue]'), true)
        ->assertMissing('[data-dashboard-refresh]')
        ->assertSeeIn('[data-dashboard-pending-count]', '1 actions awaiting approval')
        ->click('[data-page-header-more]')
        ->assertSeeIn('[data-page-header-menu] [data-page-header-item="refresh"]', 'Refresh');

    ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);

    $webpage->click('[data-page-header-item="refresh"]')
        ->assertSeeIn('[data-dashboard-pending-count]', '2 actions awaiting approval');
});
