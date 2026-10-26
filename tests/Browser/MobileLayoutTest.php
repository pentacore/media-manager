<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\WhisparrVersion;
use App\Models\ActionRequest;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSchedule;
use App\Models\ChatTemplate;
use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use App\Models\NotificationDestination;
use App\Models\ServiceConnection;
use App\Models\User;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;

/*
 * The phone-width gate (Batch 3h). At 375x812 every page route must keep its
 * content inside the screen (or inside a horizontal scroller that fits), show
 * its primary action on screen, name every control, hide nothing behind
 * hover, overlap no two controls and leave no scroller scrolling by a sliver.
 * The gate covers every page route: `the phone gate classifies every page
 * route` fails while a GET route behind `auth` is in no dataset and not in
 * mobileLayoutExcludedRouteNames(). mobileLayoutPendingRouteNames() is the
 * 3h-3 backlog of pages not gated yet; Task 13 empties and deletes it. The
 * touch dataset sweeps every page PhoneJobsTouchTargetTest does not for
 * controls under 40 px.
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
        'emby.local:8096/Users*' => Http::response([
            ['Id' => 'emby-1', 'Name' => 'Alice', 'Policy' => ['IsAdministrator' => true], 'LastActivityDate' => '2026-09-28T20:15:00Z'],
            ['Id' => 'emby-2', 'Name' => 'Bob', 'Policy' => ['IsAdministrator' => false], 'LastActivityDate' => null],
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
    // The admin tables' rows: action rules, notification destinations and an
    // Emby link. The fake runs before the test's own user exists, so the link
    // gets a user of its own; the AI conversation is the admin's own
    // (mobileLayoutSeedDetailModels()).
    test()->seed(ActionTypeConfigSeeder::class);
    NotificationDestination::factory()->discord()->count(2)->create();
    EmbyUserLink::factory()->create(['emby_user_id' => 'emby-1', 'emby_username' => 'Alice']);
}

/**
 * The models the admin detail rows name by id (901 and up, clear of the ids
 * the factories hand out): a benchmark case, two finished runs on it, a
 * schedule, a chat template and an AI conversation, all owned by $admin.
 * Two prices and three usage records fill the AI prices table and the AI
 * usage invocations table, so those rows measure their row controls.
 */
function mobileLayoutSeedDetailModels(User $admin): void
{
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini']);
    AiModelPrice::factory()->create(['provider' => 'anthropic', 'model' => 'example-model-with-a-very-long-unbroken-name-0123456789']);
    AiUsageRecord::factory()->count(3)->create(['prompt_text' => 'Find the example series and tell me about it.']);
    BenchmarkCase::factory()->create(['id' => 901, 'name' => 'Example stuck download case']);
    BenchmarkRun::factory()->completed()->create(['id' => 901, 'name' => 'Example baseline run', 'case_ids' => [901]]);
    BenchmarkRun::factory()->completed()->create(['id' => 902, 'name' => 'Example candidate run', 'case_ids' => [901]]);
    BenchmarkSchedule::factory()->create(['id' => 901, 'name' => 'Example nightly schedule', 'created_by' => $admin->id]);
    ChatTemplate::factory()->create(['id' => 901, 'name' => 'Example stuck downloads check', 'user_id' => $admin->id]);
    DB::table('agent_conversations')->insert(['id' => '01900000-0000-7000-8000-000000000901', 'participant_type' => $admin->getMorphClass(), 'participant_id' => $admin->id, 'title' => 'Example conversation about a long series title', 'created_at' => now(), 'updated_at' => now()]);
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
 * Visible links and buttons in $root (the first match, default `main`) with no
 * accessible name, as a ' | ' list.
 */
function mobileLayoutUnnamedScript(string $root = 'main'): string
{
    $selector = json_encode($root, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => [...document.querySelector({$selector}).querySelectorAll('a[href], button, [role="button"]')]
            .filter((element) => { const rect = element.getBoundingClientRect(); return rect.width > 0 && rect.height > 0 && getComputedStyle(element).visibility !== 'hidden'; })
            .filter((element) => !(element.getAttribute('aria-label') || element.getAttribute('aria-labelledby') || element.getAttribute('title') || (element.textContent || '').trim() || (element.closest('label')?.textContent || '').trim()))
            .map((element) => element.outerHTML.slice(0, 80))
            .slice(0, 10)
            .join(' | '))()
    JS;
}

/**
 * Visible links and buttons in $root (the first match, default `main`) whose
 * own or inherited opacity below the root is 0 — controls a phone (no hover)
 * could never see.
 */
function mobileLayoutHoverOnlyScript(string $root = 'main'): string
{
    $selector = json_encode($root, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => { const root = document.querySelector({$selector}); return [...root.querySelectorAll('a[href], button, [role="button"], [role="checkbox"]')]
            .filter((element) => { const rect = element.getBoundingClientRect(); return rect.width > 0 && rect.height > 0; })
            .filter((element) => { for (let node = element; node && node !== root; node = node.parentElement) { if (getComputedStyle(node).opacity === '0') { return true; } } return false; })
            .map((element) => element.outerHTML.slice(0, 80))
            .slice(0, 10)
            .join(' | '); })()
    JS;
}

/**
 * Runs every phone-gate check on $root (default `main`; the guest pages have
 * no `<main>` and pass `body`): nothing past the screen, no sideways scroll,
 * no unnamed or hover-only control, no two overlapping controls and no scroll
 * sliver, plus the primary action on screen.
 */
function mobileLayoutAssertPage(AwaitableWebpage|PendingAwaitablePage|Webpage $webpage, ?string $primary, ?string $deferred = null, string $root = 'main'): void
{
    mobileLayoutSettle($webpage, $deferred);

    if ($deferred !== null) {
        $webpage->assertPresent($deferred);
    }

    $encodedRoot = json_encode($root, JSON_THROW_ON_ERROR);

    $webpage->assertScript(phoneOverflowScript($root), '')
        ->assertScript("document.querySelector({$encodedRoot}).scrollWidth - document.querySelector({$encodedRoot}).clientWidth", 0)
        ->assertScript(mobileLayoutUnnamedScript($root), '')
        ->assertScript(mobileLayoutHoverOnlyScript($root), '')
        ->assertScript(phoneOverlapScript($root), '')
        ->assertScript(phoneScrollSliverScript($root), '');

    if ($primary !== null) {
        $encoded = json_encode($primary, JSON_THROW_ON_ERROR);
        $webpage->assertScript("(() => { const element = document.querySelector({$encoded}); if (!element) { return 'missing'; } const rect = element.getBoundingClientRect(); return rect.width > 0 && rect.height > 0 && rect.left >= 0 && rect.right <= window.innerWidth ? 'visible' : 'off-screen'; })()", 'visible');
    }
}

/**
 * @return array<string, array{0: string, 1: ?string, 2?: ?string, 3?: array<string, mixed>}>
 */
function mobileLayoutMemberPages(): array
{
    return [
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
    ];
}

/**
 * @return array<string, array{0: string, 1: ?string, 2?: ?string, 3?: array<string, mixed>}>
 */
function mobileLayoutAdminPages(): array
{
    return [
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
        'action rules' => ['actions.rules.index', null],
        'emby links' => ['emby.links.index', null],
        'notification destinations' => ['admin.notification-destinations.index', '[data-destination-add]'],
        'ai conversations' => ['admin.ai-conversations.index', null],
        'ai models' => ['admin.ai-models.index', null],
        'ai prices' => ['admin.ai-prices.index', '[data-create-price]'],
        'ai conversation' => ['admin.ai-conversations.show', null, null, ['conversation' => '01900000-0000-7000-8000-000000000901']],
        'benchmark runs' => ['admin.benchmarks.runs.index', '[data-benchmark-run-new]'],
        'new benchmark run' => ['admin.benchmarks.runs.create', null],
        'benchmark run' => ['admin.benchmarks.runs.show', null, null, ['benchmarkRun' => 901]],
        // The request rebuilds run_ids from the comma list in `runs`; a bad
        // list redirects to the runs index, which has no [data-compare-page].
        'benchmark comparison' => ['admin.benchmarks.compare.show', null, '[data-compare-page]', ['runs' => '901,902']],
        'benchmark cases' => ['admin.benchmarks.cases.index', '[data-benchmark-case-new]'],
        'new benchmark case' => ['admin.benchmarks.cases.create', null],
        'benchmark case' => ['admin.benchmarks.cases.edit', null, null, ['benchmarkCase' => 901]],
        'new benchmark schedule' => ['admin.benchmarks.schedules.create', null],
        'benchmark schedule' => ['admin.benchmarks.schedules.edit', null, null, ['benchmarkSchedule' => 901]],
        'chat templates' => ['ai.templates.index', '[data-template-new]'],
        'new chat template' => ['ai.templates.create', null],
        'chat template' => ['ai.templates.edit', null, null, ['chatTemplate' => 901]],
        'ai chat' => ['ai.chat', '[data-chat-options]'],
        'requests (admin)' => ['media.requests.index', '[data-page-header-more]', '[data-request-card="1"]'],
        'action queue (admin)' => ['actions.requests.index', '[data-action-refresh]', '[data-action-cards]'],
    ];
}

/**
 * The guest pages render no `<main>`: their rows are measured on `body`.
 *
 * @return array<string, array{0: string, 1: ?string, 2?: ?string, 3?: array<string, mixed>}>
 */
function mobileLayoutGuestPages(): array
{
    return [
        // 'welcome' (home) waits for a ruling: its decorative 438 px logo SVG ends past the screen inside an overflow-hidden frame.
        // Login.vue has two [data-test="login-button"] branches (with and without SSO), so it gets no primary hook.
        'login' => ['login', null],
        'register' => ['register', '[data-test="register-user-button"]'],
        'forgot password' => ['password.request', '[data-test="email-password-reset-link-button"]'],
    ];
}

/**
 * Pages swept for controls under 40 px, as [routeName, parameters, deferred].
 *
 * @return array<string, array{0: string, 1: array<string, mixed>, 2: ?string}>
 */
function mobileLayoutTouchPages(): array
{
    return [
        'notifications' => ['notifications.index', [], null],
        'profile' => ['profile.edit', [], null],
        'users' => ['admin.users.index', [], null],
        'connections' => ['admin.connections.index', [], null],
        'notification destinations' => ['admin.notification-destinations.index', [], null],
        'action rules' => ['actions.rules.index', [], null],
        'servers' => ['admin.servers.index', [], null],
        'server incidents' => ['admin.servers.incidents.index', [], null],
        'benchmark schedules' => ['admin.benchmarks.schedules.index', [], null],
        'ai conversations' => ['admin.ai-conversations.index', [], null],
        'emby links' => ['emby.links.index', [], null],
        'ai settings' => ['admin.ai-settings.index', [], null],
        'decision agent' => ['admin.decision-agent.index', [], null],
        'ai usage' => ['admin.ai-usage.index', [], '[data-classification-gates]'],
        'ai models' => ['admin.ai-models.index', [], null],
        'ai prices' => ['admin.ai-prices.index', [], null],
        'ai conversation' => ['admin.ai-conversations.show', ['conversation' => '01900000-0000-7000-8000-000000000901'], null],
        'benchmark runs' => ['admin.benchmarks.runs.index', [], null],
        'new benchmark run' => ['admin.benchmarks.runs.create', [], null],
        'benchmark run' => ['admin.benchmarks.runs.show', ['benchmarkRun' => 901], null],
        'benchmark comparison' => ['admin.benchmarks.compare.show', ['runs' => '901,902'], '[data-compare-page]'],
        'benchmark cases' => ['admin.benchmarks.cases.index', [], null],
        'new benchmark case' => ['admin.benchmarks.cases.create', [], null],
        'benchmark case' => ['admin.benchmarks.cases.edit', ['benchmarkCase' => 901], null],
        'new benchmark schedule' => ['admin.benchmarks.schedules.create', [], null],
        'benchmark schedule' => ['admin.benchmarks.schedules.edit', ['benchmarkSchedule' => 901], null],
        'chat templates' => ['ai.templates.index', [], null],
        'new chat template' => ['ai.templates.create', [], null],
        'chat template' => ['ai.templates.edit', ['chatTemplate' => 901], null],
    ];
}

/**
 * GET routes behind `auth` that render no page of their own, each with the
 * reason the gate leaves it out.
 *
 * @return array<string, string>
 */
function mobileLayoutExcludedRouteNames(): array
{
    return [
        'ai.chat.pending-workflow' => 'JSON for the chat workflow resume',
        'ai.chat.attachments.show' => 'a chat attachment file download',
        'ai.chat.model-options' => 'JSON for the chat model picker',
        'ai.chat.tool-groups' => 'JSON for the chat tool-group picker',
        'ai.conversations.index' => 'JSON for the chat conversation list',
        'ai.conversations.show' => 'JSON for one chat conversation',
        'ai.conversations.tool-groups.show' => "JSON for a conversation's pinned tool groups",
        'ai.templates.options' => 'JSON for the chat template picker',
        'ai.templates.library' => 'JSON for the chat template library search',
        'activity-log.export' => 'a CSV export download',
        'security.edit' => 'password confirmation first (R6)',
        'admin.ai-usage.export' => 'a CSV export download',
        'admin.ai-usage.show' => 'JSON for the AI usage record drawer',
        'admin.ai-prices.catalog.index' => 'JSON for the AI price catalog picker',
        'media.search.instant' => 'JSON for the instant search dropdown',
        'media.discover.title' => 'JSON for the title sheet',
        'media.replacement.inspect' => 'JSON for the media replacement dialog',
        'media.replacement.candidates' => 'JSON for the media replacement candidates',
        'media.library.actions.releases' => 'JSON for the interactive release search',
        'media.library.activity.manual-import.candidates' => 'JSON for the manual import dialog',
        'media.requests.edit-options' => 'JSON for the request edit dialog',
        'monitoring.watch-history.export' => 'a CSV export download',
        'bazarr.capabilities' => 'JSON for the subtitle drawer capabilities',
        'bazarr.search' => 'JSON for the subtitle search',
        'verification.verify' => 'a signed email-verification link that redirects',
        'password.confirmation' => 'JSON for the password-confirmed status',
        'two-factor.qr-code' => 'JSON for the two-factor QR code',
        'two-factor.secret-key' => 'JSON for the two-factor secret key',
        'two-factor.recovery-codes' => 'JSON for the two-factor recovery codes',
    ];
}

/**
 * The 3h-3 backlog: page routes not gated yet, in route-file order. Each
 * later task moves its pages into a dataset; Task 13 empties and deletes it.
 *
 * @return list<string>
 */
function mobileLayoutPendingRouteNames(): array
{
    return [
        // Fortify's auth pages (auth layout, no <main>): Task 7.
        'verification.notice',
        'password.confirm',
        'activity-log',
        'appearance.edit',
        'admin.connections.create',
        'admin.connections.edit',
        'admin.media-replacement.attempts.index',
        'admin.media-replacement.attempts.show',
        'admin.webhook-log.show',
        'admin.servers.show',
        'media.series.create',
        'media.movies.create',
        'media.wanted.index',
        'media.anime.index',
        'media.whisparr.show',
        'prowlarr.search',
        'bazarr.overview',
        'bazarr.missing',
        'bazarr.library',
        'bazarr.history',
        'bazarr.escalations',
        'bazarr.admin.index',
    ];
}

/**
 * Named GET routes behind `auth` or `auth:<guard>` (Fortify registers its
 * routes with `auth:web`): every one needs a dataset row, an exclusion or a
 * pending entry.
 *
 * @return list<string>
 */
function mobileLayoutPageRouteNames(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (Illuminate\Routing\Route $route): bool => in_array('GET', $route->methods(), true))
        ->filter(fn (Illuminate\Routing\Route $route): bool => collect($route->gatherMiddleware())->contains(fn (mixed $middleware): bool => $middleware === 'auth' || (is_string($middleware) && str_starts_with($middleware, 'auth:'))))
        ->map(fn (Illuminate\Routing\Route $route): ?string => $route->getName())
        ->filter()
        ->unique()
        ->values()
        ->all();
}

/**
 * Page routes that are in no dataset, not excluded and not pending.
 *
 * @return list<string>
 */
function mobileLayoutUnclassifiedRouteNames(): array
{
    $classified = collect([...mobileLayoutMemberPages(), ...mobileLayoutAdminPages(), ...mobileLayoutGuestPages()])
        ->map(fn (array $row): string => $row[0])
        ->merge(array_keys(mobileLayoutExcludedRouteNames()))
        ->merge(mobileLayoutPendingRouteNames());

    return collect(mobileLayoutPageRouteNames())->diff($classified)->values()->all();
}

dataset('mobile layout member pages', mobileLayoutMemberPages());
dataset('mobile layout admin pages', mobileLayoutAdminPages());
dataset('mobile layout guest pages', mobileLayoutGuestPages());
dataset('mobile layout touch pages', mobileLayoutTouchPages());

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
    $admin = User::factory()->admin()->create(['name' => 'Example Admin']);
    mobileLayoutSeedDetailModels($admin);
    $this->actingAs($admin);

    mobileLayoutAssertPage(visit(route($routeName, $parameters, absolute: false))->on()->mobile()->assertNoSmoke(), $primary, $deferred);
})->with('mobile layout admin pages');

/**
 * @param  array<string, int|string>  $parameters
 */
test('a guest page fits a phone screen', function (string $routeName, ?string $primary, ?string $deferred = null, array $parameters = []): void {
    mobileLayoutAssertPage(visit(route($routeName, $parameters, absolute: false))->on()->mobile()->assertNoSmoke(), $primary, $deferred, 'body');
})->with('mobile layout guest pages');

/**
 * @param  array<string, int|string>  $parameters
 */
test('every control on a page is a 40 px target on a phone', function (string $routeName, array $parameters, ?string $deferred): void {
    config()->set('mediamanager.ai.enabled', true);
    mobileLayoutFakeServices();
    $admin = User::factory()->admin()->create(['name' => 'Example Admin']);
    mobileLayoutSeedDetailModels($admin);
    $this->actingAs($admin);

    $webpage = visit(route($routeName, $parameters, absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    mobileLayoutSettle($webpage, $deferred);

    $webpage->assertScript(phoneSmallTargetsScript(), '');
})->with('mobile layout touch pages');

test('an open dialog fits a phone screen', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.users.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-users-invite]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[role=\"dialog\"]") && document.querySelector("[role=\"dialog\"]").getAnimations().length === 0'));
    mobileLayoutSettle($webpage);

    $webpage->assertScript(phoneOverflowScript('[role="dialog"]'), '')
        ->assertScript(phoneOverlapScript('[role="dialog"]'), '')
        ->assertScript(phoneScrollSliverScript('[role="dialog"]'), '')
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
        ->assertScript(phoneOverlapScript('[data-action-sheet]'), '')
        ->assertScript(phoneScrollSliverScript('[data-action-sheet]'), '')
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
        ->assertScript(phoneOverlapScript('[data-slot="sheet-content"]'), '')
        ->assertScript(phoneScrollSliverScript('[data-slot="sheet-content"]'), '')
        ->assertScript('(() => { const rect = document.querySelector("[data-slot=\"sheet-content\"]").getBoundingClientRect(); return rect.left >= 0 && rect.right <= window.innerWidth && rect.top >= 0 && rect.bottom <= window.innerHeight; })()', true);
});

test('the settle wait fails loudly when the deferred content never renders', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('profile.edit', absolute: false))->on()->mobile()->assertNoSmoke();

    expect(fn () => mobileLayoutSettle($webpage, '[data-never-rendered]'))
        ->toThrow('the deferred content [data-never-rendered] never rendered');
});

test('the overlap check names two overlapping controls and excuses nested or clipped ones', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $webpage = visit(route('profile.edit', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->script('document.querySelector("main").insertAdjacentHTML("afterbegin", `<div data-overlap-fixture style="position:relative;height:60px"><button style="position:absolute;left:0;top:0;width:100px;height:40px">One</button><button style="position:absolute;left:60px;top:10px;width:100px;height:40px">Two</button></div>`)');

    $overlap = $webpage->script(phoneOverlapScript());
    expect($overlap)->toContain('"One"')->toContain('"Two"')->toContain(' × ');

    // A disabled Button is pointer-events: none yet keeps its box, so it still collides.
    $webpage->script('document.querySelector("[data-overlap-fixture]").outerHTML = `<div data-overlap-fixture style="position:relative;height:60px"><button disabled style="position:absolute;left:0;top:0;width:100px;height:40px;pointer-events:none">Off</button><button style="position:absolute;left:60px;top:10px;width:100px;height:40px">On</button></div>`');
    expect($webpage->script(phoneOverlapScript()))->toContain('"Off"')->toContain('"On"');

    // A small control wholly inside a bigger one's box (a checkbox on a card link) is the overlay pattern.
    $webpage->script('document.querySelector("[data-overlap-fixture]").outerHTML = `<div data-overlap-fixture style="position:relative;height:120px"><a href="#" style="position:absolute;inset:0">Card</a><button style="position:absolute;left:8px;top:8px;width:40px;height:40px" aria-label="Pick">x</button></div>`');
    $webpage->assertScript(phoneOverlapScript(), '');

    // A control its scroller clips away overlaps nothing on screen.
    $webpage->script('document.querySelector("[data-overlap-fixture]").outerHTML = `<div data-overlap-fixture style="position:relative;height:60px"><div style="width:100px;overflow:hidden;white-space:nowrap"><button style="margin-left:150px;width:80px;height:40px">Hidden</button></div><button style="position:absolute;left:150px;top:0;width:80px;height:40px">Shown</button></div>`');
    $webpage->assertScript(phoneOverlapScript(), '');

    // An inline link wrapping onto a second line is measured by its line boxes, not its bounding box,
    // so a taller button after it on the second line overlaps nothing.
    $webpage->script('document.querySelector("[data-overlap-fixture]").outerHTML = `<div data-overlap-fixture style="width:200px;font-size:16px;line-height:20px"><a href="#">An inline link that wraps here</a> <button style="height:40px;vertical-align:top">Next</button></div>`');
    $webpage->assertScript('(() => { const link = document.querySelector("[data-overlap-fixture] a"); const button = document.querySelector("[data-overlap-fixture] button"); const box = link.getBoundingClientRect(); const target = button.getBoundingClientRect(); return link.getClientRects().length === 2 && target.top < box.bottom && target.left < box.right && target.bottom > box.bottom; })()', true);
    $webpage->assertScript(phoneOverlapScript(), '');
});

test('the sliver check names a scroller that scrolls by a few pixels', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $webpage = visit(route('profile.edit', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    // A 32 px touch-target in a 32 px scroller: its 40 px ::after overhangs 4 px below.
    $webpage->script('document.querySelector("main").insertAdjacentHTML("afterbegin", `<div data-sliver-fixture style="height:32px;overflow-y:auto"><button class="touch-target" style="display:block;height:32px">Sliver</button></div>`)');
    expect($webpage->script(phoneScrollSliverScript()))->toContain('[data-sliver-fixture]')->toContain('by 4px');

    // Content that really scrolls is not a sliver.
    $webpage->script('document.querySelector("[data-sliver-fixture]").outerHTML = `<div data-sliver-fixture style="height:100px;overflow-y:auto"><div style="height:300px">Tall</div></div>`');
    $webpage->assertScript(phoneScrollSliverScript(), '');
});

test('the phone gate classifies every page route', function (): void {
    $listed = collect([...mobileLayoutMemberPages(), ...mobileLayoutAdminPages(), ...mobileLayoutGuestPages()])->map(fn (array $row): string => $row[0])->unique();
    $excluded = collect(mobileLayoutExcludedRouteNames())->keys();
    $pending = collect(mobileLayoutPendingRouteNames());

    $pageRoutes = collect(mobileLayoutPageRouteNames());

    expect(mobileLayoutUnclassifiedRouteNames())->toBe([])
        ->and($listed->merge($excluded)->merge($pending)->diff($pageRoutes)->diff(collect(mobileLayoutGuestPages())->map(fn (array $row): string => $row[0]))->values()->all())->toBe([])
        ->and($pending->intersect($listed->merge($excluded))->values()->all())->toBe([]);
});

test('the route guard catches an unlisted page behind auth or an auth guard', function (): void {
    Route::middleware(['web', 'auth'])->get('example-auth-page', fn (): Response => Inertia::render('Welcome'))->name('example.auth-page');
    Route::middleware(['web', 'auth:web'])->get('example-guarded-page', fn (): Response => Inertia::render('Welcome'))->name('example.guarded-page');

    expect(mobileLayoutUnclassifiedRouteNames())->toBe(['example.auth-page', 'example.guarded-page']);
});
