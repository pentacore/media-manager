<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\WhisparrVersion;
use App\Models\ActionRequest;
use App\Models\EmbyActivity;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;

/*
 * The phone-width gate (Batch 3h). At 375x812 every listed page must keep its
 * content inside the screen (or inside a horizontal scroller that fits), show
 * its primary action on screen, name every control and hide nothing behind
 * hover. Not listed yet, each for a named later fix:
 *   activity-log                 nowrap rows (3h-3)
 *   media.whisparr.show          detail toolbar (3h-3)
 *   other admin pages            reachability pass (3h-3)
 */

/**
 * @return array<string, mixed>
 */
function mobileLayoutSeries(int $id, string $title): array
{
    return [
        'id' => $id, 'title' => $title, 'titleSlug' => sprintf('example-%d', $id), 'year' => 2024, 'status' => 'continuing',
        'monitored' => true, 'qualityProfileId' => 1, 'seasons' => [], 'network' => 'Example Network',
        'images' => [['coverType' => 'poster', 'remoteUrl' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7']],
        'statistics' => ['sizeOnDisk' => 8_000_000_000, 'episodeCount' => 6, 'episodeFileCount' => 4],
    ];
}

function mobileLayoutFakeServices(): void
{
    $poster = [['coverType' => 'poster', 'remoteUrl' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7']];
    ServiceConnection::factory()->sonarr()->create(['name' => 'Sonarr', 'url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    ServiceConnection::factory()->radarr()->create(['name' => 'Radarr', 'url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    ServiceConnection::factory()->sabnzbd()->create(['name' => 'SABnzbd', 'url' => 'http://sabnzbd.local:8080', 'api_key' => 'k']);
    ServiceConnection::factory()->emby()->create(['name' => 'Emby', 'url' => 'http://emby.local:8096', 'api_key' => 'k']);
    ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V3)->create(['name' => 'Whisparr', 'url' => 'http://whisparr.local:6969', 'api_key' => 'k']);
    ServiceConnection::factory()->seerr()->create(['name' => 'Seerr', 'url' => 'http://seerr.local:5055', 'api_key' => 'k']);

    $slots = [];
    foreach (range(1, 3) as $index) {
        $slots[] = ['nzo_id' => 'SABnzbd_nzo_'.$index, 'filename' => 'Severance.S01E0'.$index.'.1080p.WEB-DL.DDP5.1.H.264-EXAMPLEGROUP', 'cat' => 'tv', 'size' => '1.0 GB', 'sizeleft' => '0.5 GB', 'percentage' => '50', 'timeleft' => '0:05:00', 'status' => 'Downloading', 'priority' => '0', 'mb' => '1024', 'mbleft' => '512'];
    }

    Http::fake([
        'sonarr.local:8989/api/v3/series' => Http::response([
            mobileLayoutSeries(1, 'Severance'),
            mobileLayoutSeries(2, 'The Extraordinarily Long Example Series Title Of Doom'),
        ]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'sonarr.local:8989/api/v3/queue*' => Http::response(['page' => 1, 'totalRecords' => 1, 'records' => [[
            'id' => 41, 'title' => 'The.Extraordinarily.Long.Example.Series.Title.Of.Doom.S01E01.1080p.WEB-DL-EXAMPLEGROUP',
            'series' => ['title' => 'The Extraordinarily Long Example Series Title Of Doom'], 'seriesId' => 2,
            'episode' => ['seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Pilot'], 'status' => 'downloading',
            'trackedDownloadStatus' => 'ok', 'trackedDownloadState' => 'downloading', 'protocol' => 'usenet', 'downloadClient' => 'SABnzbd',
            'size' => 1_000_000_000, 'sizeleft' => 500_000_000, 'timeleft' => '00:05:00', 'statusMessages' => [],
            'added' => '2026-09-29T10:00:00Z', 'quality' => ['quality' => ['name' => 'WEBDL-1080p']], 'downloadId' => 'SABnzbd_nzo_41',
        ]]]),
        'sonarr.local:8989/api/v3/series/7*' => Http::response(array_merge(mobileLayoutSeries(7, 'Severance'), [
            'overview' => 'An example overview.',
            'seasons' => [['seasonNumber' => 1, 'monitored' => true, 'statistics' => ['episodeCount' => 1, 'episodeFileCount' => 1, 'totalEpisodeCount' => 1, 'sizeOnDisk' => 1_000_000_000]]],
        ])),
        'sonarr.local:8989/api/v3/episode*' => Http::response([
            ['id' => 71, 'seriesId' => 7, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'An Example Episode Title That Runs Long', 'airDate' => '2022-02-18', 'hasFile' => true, 'monitored' => true],
        ]),
        'sonarr.local:8989/api/v3/calendar*' => Http::response([
            ['id' => 71, 'seriesId' => 7, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'An Example Episode Title That Runs Long', 'airDateUtc' => now()->startOfMonth()->addDays(9)->setTime(2, 0)->toIso8601String(), 'hasFile' => false, 'monitored' => true,
                'series' => ['title' => 'The Extraordinarily Long Example Series Title Of Doom', 'monitored' => true, 'images' => $poster]],
        ]),
        'sonarr.local:8989/*' => Http::response([]),
        'radarr.local:7878/api/v3/movie' => Http::response([
            ['id' => 10, 'title' => 'An Extremely Long Example Movie Title That Wraps Badly', 'titleSlug' => 'example-10', 'year' => 2021, 'monitored' => true, 'hasFile' => false, 'qualityProfileId' => 1, 'sizeOnDisk' => 0, 'images' => $poster],
        ]),
        'radarr.local:7878/api/v3/queue*' => Http::response(['page' => 1, 'totalRecords' => 0, 'records' => []]),
        'radarr.local:7878/api/v3/movie/10*' => Http::response(['id' => 10, 'title' => 'An Extremely Long Example Movie Title That Wraps Badly', 'titleSlug' => 'example-10', 'year' => 2021, 'monitored' => true, 'hasFile' => true, 'qualityProfileId' => 1, 'sizeOnDisk' => 12_000_000_000, 'images' => $poster, 'status' => 'released']),
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'radarr.local:7878/api/v3/calendar*' => Http::response([]),
        'radarr.local:7878/*' => Http::response([]),
        'whisparr.local:6969/api/v3/movie*' => Http::response([['id' => 11, 'title' => 'Example Scene Title', 'year' => 2024, 'monitored' => true, 'hasFile' => false, 'sizeOnDisk' => 0, 'qualityProfileId' => 1, 'images' => []]]),
        'whisparr.local:6969/*' => Http::response([]),
        'sabnzbd.local:8080/api*' => function (Request $request) use ($slots) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (($query['mode'] ?? null) === 'queue') {
                return Http::response(['queue' => ['paused' => false, 'speed' => '10 M', 'kbpersec' => '10240', 'speedlimit' => '', 'speedlimit_abs' => '', 'slots' => $slots, 'noofslots' => count($slots), 'sizeleft' => '1.5 GB', 'timeleft' => '0:15:00']]);
            }

            if (($query['mode'] ?? null) === 'history') {
                return Http::response(['history' => ['slots' => [], 'noofslots' => 0]]);
            }

            return Http::response(['status' => true]);
        },
        'emby.local:8096/Sessions*' => Http::response([
            ['Id' => 's1', 'UserName' => 'Alice', 'UserId' => 'emby-1', 'Client' => 'Emby Web', 'DeviceName' => 'Living Room TV', 'NowPlayingItem' => ['Id' => 'i1', 'Name' => 'An Extremely Long Example Movie Title That Wraps Badly', 'Type' => 'Movie', 'RunTimeTicks' => 66_000_000_000], 'PlayState' => ['PositionTicks' => 6_000_000_000, 'IsPaused' => true]],
        ]),
        'emby.local:8096/*' => Http::response([]),
        'seerr.local:5055/api/v1/request/count' => Http::response(['total' => 1, 'pending' => 1, 'approved' => 0, 'declined' => 0, 'movie' => 0, 'tv' => 1]),
        'seerr.local:5055/api/v1/request*' => Http::response(['pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 1], 'results' => [
            ['id' => 1, 'status' => 1, 'type' => 'tv', 'media' => ['mediaType' => 'tv', 'tmdbId' => 95396, 'tvdbId' => 371980, 'status' => 3],
                'requestedBy' => ['id' => 7, 'displayName' => 'Example Requester With A Long Name', 'email' => 'requester@example.com'], 'createdAt' => '2026-10-01T00:00:00Z', 'seasons' => [['seasonNumber' => 1]]],
        ]]),
        'seerr.local:5055/api/v1/tv/*' => Http::response(['id' => 95396, 'name' => 'The Extraordinarily Long Example Series Title Of Doom', 'posterPath' => '/example.jpg', 'firstAirDate' => '2022-02-17']),
        'seerr.local:5055/api/v1/user*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1], 'results' => [['id' => 7, 'email' => 'requester@example.com', 'displayName' => 'Example Requester']]]),
        'seerr.local:5055/api/v1/discover/trending*' => Http::response(['results' => [
            ['id' => 95396, 'mediaType' => 'tv', 'name' => 'The Extraordinarily Long Example Series Title Of Doom', 'firstAirDate' => '2022-02-17', 'posterPath' => '/example.jpg'],
            ['id' => 438631, 'mediaType' => 'movie', 'title' => 'Dune', 'releaseDate' => '2021-10-22', 'posterPath' => '/dune.jpg'],
        ]]),
        'seerr.local:5055/api/v1/search*' => Http::response(['results' => [
            ['id' => 438631, 'mediaType' => 'movie', 'title' => 'Dune: An Extremely Long Example Movie Title That Wraps', 'releaseDate' => '2021-10-22', 'posterPath' => '/dune.jpg'],
        ]]),
        'seerr.local:5055/*' => Http::response([]),
    ]);

    ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "'.str_repeat('Severance', 6).'"']);
    ActionRequest::factory()->described()->failed()->create();
    // Played and without a series title, so Statistics' Top titles always
    // lists this long title (the factory's random action and series title
    // used to show it only sometimes, which made the statistics row flaky).
    EmbyActivity::factory()->create(['media_type' => 'movie', 'media_title' => 'An Extremely Long Example Movie Title That Wraps Badly', 'series_title' => null, 'action' => 'played']);
}

/**
 * Waits for deferred props and web fonts: no loading placeholder left in main
 * or an open dialog — the shared Skeleton (`data-slot="skeleton"`) or a
 * hand-rolled `animate-pulse` fallback (Statistics, AI usage), minus the
 * header's realtime dot — and, when given, the route's deferred content.
 * Throws after ~10 s naming what is still pending, so a slow deferred prop
 * fails the row instead of letting it measure the placeholder.
 */
function mobileLayoutSettle(AwaitableWebpage|PendingAwaitablePage|Webpage $webpage, ?string $deferred = null): void
{
    $loading = json_encode('main [data-slot="skeleton"], main .animate-pulse:not(header *), [role="dialog"] [data-slot="skeleton"], [role="dialog"] .animate-pulse', JSON_THROW_ON_ERROR);
    $deferredSelector = json_encode($deferred, JSON_THROW_ON_ERROR);

    $webpage->script(<<<JS
        (async () => {
            const pending = () => {
                const placeholder = document.querySelector({$loading});
                if (placeholder) {
                    return `a loading placeholder is still shown: \${placeholder.outerHTML.slice(0, 120)}`;
                }
                if ({$deferredSelector} !== null && !document.querySelector({$deferredSelector})) {
                    return `the deferred content \${{$deferredSelector}} never rendered`;
                }
                if (document.fonts.status !== 'loaded') {
                    return 'the web fonts never finished loading';
                }
                return null;
            };
            for (let attempt = 0; attempt < 500; attempt++) {
                if (pending() === null) {
                    return;
                }
                await new Promise((resolve) => setTimeout(resolve, 20));
            }
            throw new Error(`The page never settled: \${pending()}`);
        })()
    JS);
}

/**
 * Visible links and buttons in main with no accessible name, as a ' | ' list.
 */
function mobileLayoutUnnamedScript(): string
{
    return <<<'JS'
        (() => [...document.querySelectorAll('main a[href], main button, main [role="button"]')]
            .filter((element) => { const rect = element.getBoundingClientRect(); return rect.width > 0 && rect.height > 0 && getComputedStyle(element).visibility !== 'hidden'; })
            .filter((element) => !(element.getAttribute('aria-label') || element.getAttribute('aria-labelledby') || element.getAttribute('title') || (element.textContent || '').trim() || (element.closest('label')?.textContent || '').trim()))
            .map((element) => element.outerHTML.slice(0, 80))
            .slice(0, 10)
            .join(' | '))()
    JS;
}

/**
 * Visible links and buttons in main whose own or inherited opacity is 0 —
 * controls a phone (no hover) could never see.
 */
function mobileLayoutHoverOnlyScript(): string
{
    return <<<'JS'
        (() => [...document.querySelectorAll('main a[href], main button, main [role="button"], main [role="checkbox"]')]
            .filter((element) => { const rect = element.getBoundingClientRect(); return rect.width > 0 && rect.height > 0; })
            .filter((element) => { for (let node = element; node && node.tagName !== 'MAIN'; node = node.parentElement) { if (getComputedStyle(node).opacity === '0') { return true; } } return false; })
            .map((element) => element.outerHTML.slice(0, 80))
            .slice(0, 10)
            .join(' | '))()
    JS;
}

function mobileLayoutAssertPage(AwaitableWebpage|PendingAwaitablePage|Webpage $webpage, ?string $primary, ?string $deferred = null): void
{
    mobileLayoutSettle($webpage, $deferred);

    if ($deferred !== null) {
        $webpage->assertPresent($deferred);
    }

    $webpage->assertScript(phoneOverflowScript(), '')
        ->assertScript('document.querySelector("main").scrollWidth - document.querySelector("main").clientWidth', 0)
        ->assertScript(mobileLayoutUnnamedScript(), '')
        ->assertScript(mobileLayoutHoverOnlyScript(), '');

    if ($primary !== null) {
        $encoded = json_encode($primary, JSON_THROW_ON_ERROR);
        $webpage->assertScript("(() => { const element = document.querySelector({$encoded}); if (!element) { return 'missing'; } const rect = element.getBoundingClientRect(); return rect.width > 0 && rect.height > 0 && rect.left >= 0 && rect.right <= window.innerWidth ? 'visible' : 'off-screen'; })()", 'visible');
    }
}

dataset('mobile layout member pages', [
    'dashboard' => ['dashboard', '[data-dashboard-review-queue]'],
    'series' => ['media.series.index', '[data-add-series]'],
    'movies' => ['media.movies.index', '[data-add-movie]'],
    // The detail rows wait for their deferred episodes / quality profiles.
    'series detail' => ['media.series.show', '[data-series-actions]', '[data-season-card="1"]', ['id' => 7]],
    'movie detail' => ['media.movies.show', '[data-movie-actions]', '[data-movie-actions] [data-quality-profile-trigger]', ['id' => 10]],
    'search' => ['media.search.index', null, '[data-search-title="movie-438631"]', ['q' => 'dune']],
    'discover' => ['media.discover.index', null, '[data-discover-row="trending"] [data-title-card]'],
    'requests' => ['media.requests.index', '[data-page-header-more]', '[data-request-card="1"]'],
    // The member is the fixture's Seerr requester, so this lists request 1.
    'my requests' => ['media.requests.mine', null, '[data-my-request="1"]'],
    // Agenda is the phone default; the settle waits for it.
    'calendar' => ['media.calendar.index', null, '[data-calendar-agenda]'],
    'grab queue' => ['media.library.activity.queue', '[data-activity-refresh]'],
    'downloads' => ['sabnzbd.queue.index', '[data-sab-queue-toggle]'],
    'now playing' => ['monitoring.now-playing', '[data-now-playing-refresh]'],
    'watch history' => ['monitoring.watch-history', '[data-page-header-more]', '[data-watch-cards]'],
    'service health' => ['monitoring.service-health', '[data-run-health-checks]'],
    // The third value is a deferred panel the settle waits for: the hour heatmap.
    'statistics' => ['statistics.index', '[data-filter-strip]', '[data-statistics-heatmap]'],
    'action queue' => ['actions.requests.index', '[data-action-refresh]', '[data-action-cards]'],
    'notifications' => ['notifications.index', null],
    'profile' => ['profile.edit', null],
    'preferences' => ['settings.preferences.edit', null],
    'notification settings' => ['settings.notifications.edit', '[data-notification-save]'],
]);

dataset('mobile layout admin pages', [
    'whisparr' => ['media.whisparr.index', '[data-library-sync]'],
    'users' => ['admin.users.index', '[data-users-invite]'],
    'connections' => ['admin.connections.index', '[data-connection-add]'],
    'operational statistics' => ['admin.statistics.index', '[data-filter-strip]'],
    'ai settings' => ['admin.ai-settings.index', null],
    'decision agent' => ['admin.decision-agent.index', null],
    'media replacement' => ['admin.media-replacement.index', null],
    'ai usage' => ['admin.ai-usage.index', null, '[data-classification-gates]'],
    'webhook log' => ['admin.webhook-log.index', null],
    'jobs' => ['admin.jobs.index', '[data-jobs-refresh]'],
    'benchmark schedules' => ['admin.benchmarks.schedules.index', '[data-benchmark-schedule-new]'],
    'servers' => ['admin.servers.index', '[data-server-add]'],
    'server incidents' => ['admin.servers.incidents.index', null],
    'ai chat' => ['ai.chat', '[data-chat-options]'],
    'requests (admin)' => ['media.requests.index', '[data-page-header-more]', '[data-request-card="1"]'],
    'action queue (admin)' => ['actions.requests.index', '[data-action-refresh]', '[data-action-cards]'],
]);

/**
 * @param  array<string, int|string>  $parameters
 */
test('a member page fits a phone screen', function (string $routeName, ?string $primary, ?string $deferred = null, array $parameters = []): void {
    mobileLayoutFakeServices();
    $this->actingAs(User::factory()->member()->create(['name' => 'Example Member', 'email' => 'requester@example.com']));

    mobileLayoutAssertPage(visit(route($routeName, $parameters, absolute: false))->on()->mobile()->assertNoSmoke(), $primary, $deferred);
})->with('mobile layout member pages');

/**
 * @param  array<string, int|string>  $parameters
 */
test('an admin page fits a phone screen', function (string $routeName, ?string $primary, ?string $deferred = null, array $parameters = []): void {
    config()->set('mediamanager.ai.enabled', true);
    mobileLayoutFakeServices();
    $this->actingAs(User::factory()->admin()->create(['name' => 'Example Admin']));

    mobileLayoutAssertPage(visit(route($routeName, $parameters, absolute: false))->on()->mobile()->assertNoSmoke(), $primary, $deferred);
})->with('mobile layout admin pages');

test('an open dialog fits a phone screen', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.users.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-users-invite]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[role=\"dialog\"]") && document.querySelector("[role=\"dialog\"]").getAnimations().length === 0'));
    mobileLayoutSettle($webpage);

    $webpage->assertScript(phoneOverflowScript('[role="dialog"]'), '')
        ->assertScript('(() => { const rect = document.querySelector("[role=\"dialog\"]").getBoundingClientRect(); return rect.left >= 0 && rect.right <= window.innerWidth && rect.top >= 0 && rect.bottom <= window.innerHeight; })()', true);
});

test('an open action sheet fits a phone screen', function (): void {
    mobileLayoutFakeServices();
    $actionRequest = ActionRequest::query()->where('status', ActionRequestStatus::Pending)->sole();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click(sprintf('[data-action-card-open="%d"]', $actionRequest->id));
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-action-sheet]") && document.querySelector("[data-action-sheet]").getAnimations().length === 0'));
    mobileLayoutSettle($webpage);

    $webpage->assertScript(phoneOverflowScript('[data-action-sheet]'), '')
        ->assertScript('(() => { const rect = document.querySelector("[data-action-sheet]").getBoundingClientRect(); return rect.left >= 0 && rect.right <= window.innerWidth && rect.top >= 0 && rect.bottom <= window.innerHeight; })()', true);
});

test('the open chat sheet fits a phone screen', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-header-ai-chat-sheet]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-slot=\"sheet-content\"] [data-chat-input]") && document.querySelector("[data-slot=\"sheet-content\"]").getAnimations().length === 0'));
    mobileLayoutSettle($webpage, '[data-chat-options]');

    $webpage->assertScript(phoneOverflowScript('[data-slot="sheet-content"]'), '')
        ->assertScript('(() => { const rect = document.querySelector("[data-slot=\"sheet-content\"]").getBoundingClientRect(); return rect.left >= 0 && rect.right <= window.innerWidth && rect.top >= 0 && rect.bottom <= window.innerHeight; })()', true);
});

test('the settle wait fails loudly when the deferred content never renders', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('profile.edit', absolute: false))->on()->mobile()->assertNoSmoke();

    expect(fn () => mobileLayoutSettle($webpage, '[data-never-rendered]'))
        ->toThrow('the deferred content [data-never-rendered] never rendered');
});
