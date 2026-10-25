<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\EmbyActivity;
use App\Models\ServiceConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Every visible control in `main` whose tappable area — its box or its
 * touch-target ::after, measured with elementFromPoint from its centre — is
 * under 40 px in either direction, as "tag[hook] "text" Npx". Inline text
 * links are exempt. Empty when every control is a 40 px target.
 */
function phoneJobsSmallTargetsScript(): string
{
    return <<<'JS'
        (() => {
            const offenders = [];
            const controls = document.querySelectorAll('main a[href], main button, main [role="button"], main [role="checkbox"], main [role="switch"], main [role="tab"], main [role="combobox"], main select');
            for (const element of controls) {
                const style = getComputedStyle(element);
                if (element.closest('[aria-hidden="true"], .sr-only') || style.visibility !== 'visible' || style.pointerEvents === 'none') {
                    continue;
                }
                if (element.tagName === 'A' && style.display === 'inline') {
                    continue;
                }
                if (element.getBoundingClientRect().width === 0 || element.getBoundingClientRect().height === 0) {
                    continue;
                }
                element.scrollIntoView({ block: 'center', inline: 'center' });
                const rect = element.getBoundingClientRect();
                const x = rect.left + rect.width / 2;
                const y = rect.top + rect.height / 2;
                const hits = (px, py) => { const hit = document.elementFromPoint(px, py); return !!hit && element.contains(hit); };
                const reach = (dx, dy) => { let step = 0; while (step < 30 && hits(x + dx * (step + 1), y + dy * (step + 1))) { step++; } return step; };
                const size = hits(x, y) ? Math.min(reach(0, -1) + reach(0, 1) + 1, reach(-1, 0) + reach(1, 0) + 1) : 0;
                if (size < 39) {
                    const hook = [...element.attributes].map((attribute) => attribute.name).find((name) => name.startsWith('data-')) ?? '';
                    const text = (element.innerText || element.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim().slice(0, 24);
                    offenders.push(`${element.tagName.toLowerCase()}[${hook}] "${text}" ${size}px`);
                }
            }
            return offenders.slice(0, 15).join(' | ');
        })()
    JS;
}

function phoneJobsTouchFakes(): void
{
    $poster = [['coverType' => 'poster', 'remoteUrl' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7']];
    $series = ['id' => 7, 'title' => 'Severance', 'titleSlug' => 'severance', 'year' => 2022, 'status' => 'continuing', 'monitored' => true, 'qualityProfileId' => 1, 'images' => $poster,
        'seasons' => [['seasonNumber' => 1, 'monitored' => true, 'statistics' => ['episodeCount' => 1, 'episodeFileCount' => 1, 'totalEpisodeCount' => 1, 'sizeOnDisk' => 1_000_000_000]]],
        'statistics' => ['sizeOnDisk' => 1_000_000_000, 'episodeCount' => 1, 'episodeFileCount' => 1]];
    $movie = ['id' => 10, 'title' => 'Dune', 'titleSlug' => 'dune-2021', 'year' => 2021, 'monitored' => true, 'hasFile' => true, 'qualityProfileId' => 1, 'sizeOnDisk' => 12_000_000_000, 'images' => $poster, 'status' => 'released'];
    foreach (['sonarr' => 'http://sonarr.local:8989', 'radarr' => 'http://radarr.local:7878', 'seerr' => 'http://seerr.local:5055', 'sabnzbd' => 'http://sab.local:8080', 'emby' => 'http://emby.local:8096'] as $state => $url) {
        ServiceConnection::factory()->{$state}()->create(['url' => $url, 'api_key' => 'k']);
    }

    Http::fake([
        'sonarr.local:8989/api/v3/series/7*' => Http::response($series),
        'sonarr.local:8989/api/v3/series*' => Http::response([$series]),
        'sonarr.local:8989/api/v3/episode*' => Http::response([['id' => 71, 'seriesId' => 7, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Good News About Hell', 'airDate' => '2022-02-18', 'hasFile' => true, 'monitored' => true]]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'sonarr.local:8989/api/v3/calendar*' => Http::response([['id' => 71, 'seriesId' => 7, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Good News About Hell', 'airDateUtc' => '2026-09-10T02:00:00Z', 'hasFile' => false, 'monitored' => true, 'series' => ['title' => 'Severance', 'monitored' => true, 'images' => $poster]]]),
        'sonarr.local:8989/api/v3/queue*' => Http::response(['records' => [['id' => 41, 'title' => 'Severance.S01E01.1080p', 'series' => ['title' => 'Severance'], 'episode' => ['seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Pilot'], 'status' => 'downloading', 'trackedDownloadStatus' => 'ok', 'trackedDownloadState' => 'downloading', 'protocol' => 'usenet', 'downloadClient' => 'SABnzbd', 'size' => 1_000_000_000, 'sizeleft' => 500_000_000, 'timeleft' => '00:05:00', 'statusMessages' => [], 'added' => '2026-09-29T10:00:00Z', 'quality' => ['quality' => ['name' => 'WEBDL-1080p']], 'downloadId' => 'SABnzbd_nzo_41']]]),
        'sonarr.local:8989/*' => Http::response([]),
        'radarr.local:7878/api/v3/movie/10*' => Http::response($movie),
        'radarr.local:7878/api/v3/movie*' => Http::response([$movie]),
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'radarr.local:7878/*' => Http::response([]),
        'seerr.local:5055/api/v1/request/count' => Http::response(['total' => 1, 'pending' => 1, 'approved' => 0, 'declined' => 0, 'movie' => 0, 'tv' => 1]),
        'seerr.local:5055/api/v1/request*' => Http::response(['pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 1], 'results' => [
            ['id' => 1, 'status' => 1, 'type' => 'tv', 'media' => ['mediaType' => 'tv', 'tmdbId' => 95396, 'tvdbId' => 371980, 'status' => 3], 'requestedBy' => ['id' => 7, 'displayName' => 'Example Requester', 'email' => 'someone@example.com'], 'createdAt' => '2026-10-01T00:00:00Z', 'seasons' => [['seasonNumber' => 1]]],
        ]]),
        'seerr.local:5055/api/v1/tv/*' => Http::response(['id' => 95396, 'name' => 'Severance', 'posterPath' => '/severance.jpg', 'firstAirDate' => '2022-02-17']),
        'seerr.local:5055/api/v1/movie/*' => Http::response(['id' => 438631, 'title' => 'Dune', 'posterPath' => '/dune.jpg', 'releaseDate' => '2021-10-22']),
        'seerr.local:5055/api/v1/user*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1], 'results' => [['id' => 7, 'email' => 'someone@example.com', 'displayName' => 'Example Admin']]]),
        'seerr.local:5055/api/v1/search*' => Http::response(['results' => [['id' => 438631, 'mediaType' => 'movie', 'title' => 'Dune', 'releaseDate' => '2021-10-22', 'posterPath' => '/dune.jpg']]]),
        'seerr.local:5055/api/v1/discover/*' => Http::response(['results' => [['id' => 95396, 'mediaType' => 'tv', 'name' => 'Severance', 'firstAirDate' => '2022-02-17', 'posterPath' => '/severance.jpg']]]),
        'seerr.local:5055/*' => Http::response([]),
        'sab.local:8080/api*' => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['mode'] ?? null) === 'history'
                ? Http::response(['history' => ['noofslots' => 1, 'slots' => [['nzo_id' => 'SABnzbd_nzo_h1', 'name' => 'Andor.S01E01.1080p', 'category' => 'tv', 'status' => 'Failed', 'fail_message' => 'Repair failed']]]])
                : Http::response(['queue' => ['paused' => false, 'speed' => '1 M', 'sizeleft' => '1 GB', 'timeleft' => '0:10:00', 'speedlimit' => '', 'speedlimit_abs' => '', 'noofslots' => 1, 'slots' => [['nzo_id' => 'SABnzbd_nzo_1', 'filename' => 'Severance.S01E01.1080p', 'cat' => 'tv', 'size' => '1.0 GB', 'sizeleft' => '0.5 GB', 'percentage' => '50', 'timeleft' => '0:05:00', 'status' => 'Downloading', 'priority' => '0']]]]);
        },
        'emby.local:8096/Sessions*' => Http::response([['Id' => 's1', 'UserName' => 'Alice', 'UserId' => 'emby-1', 'Client' => 'Emby Web', 'DeviceName' => 'Living Room TV', 'NowPlayingItem' => ['Id' => 'i1', 'Name' => 'Half Loop', 'SeriesName' => 'Severance', 'Type' => 'Episode', 'RunTimeTicks' => 33_000_000_000], 'PlayState' => ['PositionTicks' => 12_000_000_000, 'IsPaused' => false]]]),
        'emby.local:8096/*' => Http::response([]),
    ]);
}

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    $this->travelTo(CarbonImmutable::parse('2026-09-15T12:00:00Z'));
    $this->seed(ActionTypeConfigSeeder::class);
    phoneJobsTouchFakes();
    ActionRequest::factory()->count(2)->create(['status' => ActionRequestStatus::Pending]);
    ActionRequest::factory()->described()->failed()->create();
    EmbyActivity::factory()->count(2)->create();
    $this->actingAs(User::factory()->admin()->create(['email' => 'someone@example.com']));
});

test('every control on a phone-job page is a 40 px target on a phone', function (string $routeName, array $parameters): void {
    $webpage = visit(route($routeName, $parameters, absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(confirmDialogWaitUntilScript('!document.querySelector("main [data-slot=\"skeleton\"]")'));

    $webpage->assertScript(phoneJobsSmallTargetsScript(), '');
})->with([
    'action queue' => ['actions.requests.index', []],
    'requests' => ['media.requests.index', []],
    'my requests' => ['media.requests.mine', []],
    'series' => ['media.series.index', []],
    'movies' => ['media.movies.index', []],
    'series detail' => ['media.series.show', ['id' => 7]],
    'movie detail' => ['media.movies.show', ['id' => 10]],
    'discover' => ['media.discover.index', []],
    'search' => ['media.search.index', ['q' => 'dune']],
    'calendar agenda' => ['media.calendar.index', []],
    'downloads' => ['sabnzbd.queue.index', []],
    'grab queue' => ['media.library.activity.queue', []],
    'now playing' => ['monitoring.now-playing', []],
    'service health' => ['monitoring.service-health', []],
    'watch history' => ['monitoring.watch-history', []],
    'ai chat' => ['ai.chat', []],
]);

test('the calendar month grid and the grab queue history tabs are 40 px targets on a phone', function (): void {
    $webpage = visit(route('media.calendar.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-calendar-view="month"]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-calendar-day]")'));
    $webpage->assertScript(phoneJobsSmallTargetsScript(), '');

    $webpage->navigate(route('media.library.activity.queue', absolute: false));
    $webpage->script(appHydratedScript());
    $webpage->click('[data-activity-tab="history"]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-history-service-tab]")'));
    $webpage->assertScript(phoneJobsSmallTargetsScript(), '');
});
