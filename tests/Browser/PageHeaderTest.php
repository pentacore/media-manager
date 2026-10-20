<?php

declare(strict_types=1);

use App\Cache\Services\SeerrCache;
use App\Enums\ActionRequestStatus;
use App\Enums\WhisparrVersion;
use App\Events\ActionRequestCreated;
use App\Jobs\ClearSeerrRequests;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Notifications\ServiceWarning;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

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

/**
 * One stateful SABnzbd fake: $state counts queue-mode reads, and the queue
 * flips to paused on the second read, so a Refresh from the menu can be
 * proven to re-fetch rather than just being present.
 */
function pageHeaderFakeSabnzbd(object $state): void
{
    ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sabnzbd.local:8080', 'api_key' => 'k']);
    Http::fake([
        'sabnzbd.local:8080/api*' => function (Request $request) use ($state) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (($query['mode'] ?? null) === 'queue') {
                $state->queueCalls++;

                return Http::response(['queue' => ['paused' => $state->queueCalls > 1, 'speed' => '10 M', 'kbpersec' => '10240', 'speedlimit' => '', 'speedlimit_abs' => '', 'slots' => [], 'noofslots' => 0, 'sizeleft' => '0 B', 'timeleft' => '0:00:00']]);
            }

            if (($query['mode'] ?? null) === 'history') {
                return Http::response(['history' => ['slots' => [], 'noofslots' => 0]]);
            }

            return Http::response(['status' => true]);
        },
    ]);
}

test("an admin's phone SABnzbd header keeps Pause queue and the speed limit, and puts Open SABnzbd and Refresh in the menu", function (): void {
    $state = new class
    {
        public int $queueCalls = 0;
    };
    pageHeaderFakeSabnzbd($state);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('sabnzbd.queue.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertScript(pageHeaderInViewportScript('[data-sab-queue-toggle]'), true)
        ->assertScript(pageHeaderInViewportScript('[data-speed-limit-trigger]'), true)
        ->assertScript(phoneOverflowScript('[data-page-header]'), '')
        ->assertSeeIn('[data-sab-queue-toggle]', 'Pause queue')
        ->click('[data-page-header-more]')
        ->assertSeeIn('[data-page-header-menu] [data-page-header-item="open-sabnzbd"]', 'Open SABnzbd')
        ->assertAttribute('[data-page-header-item="open-sabnzbd"]', 'href', 'http://sabnzbd.local:8080')
        ->assertAttribute('[data-page-header-item="open-sabnzbd"]', 'target', '_blank')
        ->assertSeeIn('[data-page-header-menu] [data-page-header-item="refresh"]', 'Refresh')
        ->click('[data-page-header-item="refresh"]')
        ->assertSeeIn('[data-sab-queue-toggle]', 'Resume queue');

    expect($state->queueCalls)->toBe(2);
});

test('an unconfigured SABnzbd header has no actions row', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('sabnzbd.queue.index', absolute: false))->on()->mobile()
        ->assertNoSmoke()
        ->assertPresent('[data-page-header]')
        ->assertNotPresent('[data-page-header-actions]')
        ->assertNotPresent('[data-page-header-more]');
});

test('watch history keeps its filters on screen and its export in the menu', function (): void {
    ServiceConnection::factory()->emby()->create(['url' => 'http://emby.local:8096', 'api_key' => 'k']);
    Http::fake(['emby.local:8096/*' => Http::response([])]);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('monitoring.watch-history', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertVisible('[data-page-header-filters]')
        ->assertScript('[...document.querySelectorAll("[data-page-header-filters] > *")].every((element) => element.getBoundingClientRect().left >= 0 && element.getBoundingClientRect().right <= window.innerWidth)', true)
        ->assertScript(phoneOverflowScript('[data-page-header]'), '')
        ->click('[data-page-header-more]')
        ->assertSeeIn('[data-page-header-menu] [data-page-header-item="export"]', 'Export CSV')
        ->assertAttribute('[data-page-header-item="export"]', 'href', '/monitoring/watch-history/export')
        ->assertSeeIn('[data-page-header-menu] [data-page-header-item="open-emby"]', 'Open Emby')
        ->assertAttribute('[data-page-header-item="open-emby"]', 'href', 'http://emby.local:8096')
        ->assertAttribute('[data-page-header-item="open-emby"]', 'target', '_blank');
});

test('the grab queue, now playing, service health, statistics, activity log, notifications and Action Queue headers fit a phone', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    ServiceConnection::factory()->emby()->create(['url' => 'http://emby.local:8096', 'api_key' => 'k']);
    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => Http::response(['page' => 1, 'totalRecords' => 0, 'records' => []]),
        'sonarr.local:8989/*' => Http::response([]),
        'radarr.local:7878/api/v3/queue*' => Http::response(['page' => 1, 'totalRecords' => 0, 'records' => []]),
        'radarr.local:7878/*' => Http::response([]),
        'emby.local:8096/*' => Http::response([]),
    ]);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.library.activity.queue', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertScript(phoneOverflowScript('[data-page-header]'), '')
        ->assertScript(pageHeaderInViewportScript('[data-activity-refresh]'), true)
        ->assertScript(pageHeaderInViewportScript('[data-service-filter="radarr"]'), true)
        ->click('[data-service-filter="radarr"]')
        ->assertScript(pageHeaderInViewportScript('[data-activity-tab="history"]'), true);

    $webpage->navigate(route('monitoring.now-playing', absolute: false));
    $webpage->assertScript(phoneOverflowScript('[data-page-header]'), '')
        ->assertScript(pageHeaderInViewportScript('[data-now-playing-refresh]'), true);

    $webpage->navigate(route('monitoring.service-health', absolute: false));
    $webpage->assertScript(phoneOverflowScript('[data-page-header]'), '')
        ->assertScript(pageHeaderInViewportScript('[data-run-health-checks]'), true);

    foreach (['statistics.index', 'activity-log', 'notifications.index'] as $routeName) {
        $webpage->navigate(route($routeName, absolute: false));
        $webpage->assertScript(phoneOverflowScript('[data-page-header]'), '');

        if ($routeName === 'activity-log') {
            $webpage->click('[data-page-header-more]')
                ->assertSeeIn('[data-page-header-menu] [data-page-header-item="export"]', 'Export NDJSON')
                ->assertAttribute('[data-page-header-item="export"]', 'href', '/activity-log/export');
        }
    }

    $webpage->navigate(route('actions.requests.index', absolute: false));
    $webpage->assertScript(phoneOverflowScript('[data-page-header]'), '')
        ->assertScript(pageHeaderInViewportScript('[data-action-refresh]'), true)
        ->assertNotPresent('[data-page-header-more]');
});

test("an admin's phone headers run the Emby library refresh and link to approval rules from their menus", function (): void {
    ServiceConnection::factory()->emby()->create(['url' => 'http://emby.local:8096', 'api_key' => 'k']);
    Http::fake([
        'emby.local:8096/Sessions' => Http::response([]),
        'emby.local:8096/Library/Refresh' => Http::response('', 204),
        'emby.local:8096/*' => Http::response([]),
    ]);
    $this->seed(ActionTypeConfigSeeder::class);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('monitoring.now-playing', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-page-header-more]')
        ->assertSeeIn('[data-page-header-menu] [data-page-header-item="open-emby"]', 'Open Emby')
        ->assertAttribute('[data-page-header-item="open-emby"]', 'href', 'http://emby.local:8096')
        ->assertAttribute('[data-page-header-item="open-emby"]', 'target', '_blank')
        ->assertSeeIn('[data-page-header-menu] [data-page-header-item="refresh-library"]', 'Refresh library')
        ->click('[data-page-header-item="refresh-library"]')
        ->assertSee('Library refresh queued.')
        ->assertNoSmoke();

    expect(ActionRequest::query()->where('type', 'emby_library_scan')->sole()->origin)->toBe('manual');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/Library/Refresh'));

    $webpage->navigate(route('actions.requests.index', absolute: false));
    $webpage->click('[data-page-header-more]')
        ->assertSeeIn('[data-page-header-menu] [data-page-header-item="approval-rules"]', 'Approval rules')
        ->assertAttribute('[data-page-header-item="approval-rules"]', 'href', '/actions/rules');
});

test("an admin's phone Action Queue header shows the live stale notice, in the viewport, and Refresh brings the new request in", function (): void {
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Example waiting request']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('actions.requests.index', ['status' => 'pending'], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $created = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Example announced request']);
    $webpage->script(echoEmitScript('members.actions', 'ActionRequestCreated', new ActionRequestCreated($created)->broadcastWith(), 4));
    $webpage->script(confirmDialogWaitUntilScript("document.querySelector('[data-action-stale]') !== null"));

    $webpage->assertSeeIn('[data-action-stale]', '1 new')
        ->assertScript(pageHeaderInViewportScript('[data-action-stale]'), true)
        ->assertScript(pageHeaderInViewportScript('[data-action-stale] button'), true)
        ->assertMissing(sprintf('[data-action-row="%d"]', $created->id))
        ->click('[data-action-stale] button');
    $webpage->script(confirmDialogWaitUntilScript(sprintf("document.querySelector('[data-action-row=\"%d\"]') !== null", $created->id)));

    $webpage->assertSeeIn(sprintf('[data-action-row="%d"]', $created->id), 'Example announced request')
        ->assertMissing('[data-action-stale]')
        ->assertNoSmoke();
});

test("a member's phone Notifications header runs Mark all read", function (): void {
    $member = User::factory()->member()->create();
    $member->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => ServiceWarning::class,
        'data' => ['service' => 'sabnzbd', 'title' => 'Disk warning', 'message' => 'Less than 1 GB free', 'level' => 'disk_full'],
    ]);
    $this->actingAs($member);

    $webpage = visit(route('notifications.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertSeeIn('[data-page-header]', '1 unread')
        ->assertScript(pageHeaderInViewportScript('[data-page-header] button'), true)
        ->click('Mark all read');

    $webpage->script(confirmDialogWaitUntilScript('!document.querySelector("[data-page-header] button")'));
    $webpage->assertSeeIn('[data-page-header]', '0 unread')
        ->assertMissing('[data-page-header] button')
        ->assertNoSmoke();
});

test('the admin list headers keep their main action on a phone', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.users.index', absolute: false))->on()->mobile()->assertNoSmoke();

    foreach ([
        'admin.users.index' => '[data-users-invite]',
        'admin.connections.index' => '[data-connection-add]',
        'admin.notification-destinations.index' => '[data-destination-add]',
        'admin.benchmarks.cases.index' => '[data-benchmark-case-new]',
        'admin.benchmarks.runs.index' => '[data-benchmark-run-new]',
        'ai.templates.index' => '[data-template-new]',
        'admin.jobs.index' => null,
        'admin.webhook-log.index' => null,
        'admin.statistics.index' => null,
    ] as $routeName => $primary) {
        $webpage->navigate(route($routeName, absolute: false));
        $webpage->assertScript(phoneOverflowScript('[data-page-header]'), '');

        if ($primary !== null) {
            $webpage->assertScript(pageHeaderInViewportScript($primary), true);
        }
    }
});

test('the invite dialog fits at phone width', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.users.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-users-invite]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[role=\"dialog\"]") && document.querySelector("[role=\"dialog\"]").getAnimations().length === 0'));

    $webpage->assertScript('(() => { const rect = document.querySelector("[role=\"dialog\"]").getBoundingClientRect(); return rect.left >= 0 && rect.right <= window.innerWidth && rect.top >= 0 && rect.bottom <= window.innerHeight; })()', true)
        ->assertScript(phoneOverflowScript('[role="dialog"]'), '');
});

test('a conversation with a very long unbroken title still fits at phone width', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = '01900000-0000-7000-8000-000000000002';
    DB::table('agent_conversations')->insert([
        'id' => $conversationId, 'participant_type' => $admin->getMorphClass(), 'participant_id' => $admin->id,
        'title' => str_repeat('Severance', 14), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($admin);

    $webpage = visit(route('admin.ai-conversations.show', ['conversation' => $conversationId], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertScript(phoneOverflowScript('[data-page-header]'), '')
        ->assertScript(pageHeaderInViewportScript('[data-conversation-archive]'), true)
        ->click('[data-page-header-more]')
        ->assertSeeIn('[data-page-header-menu] [data-page-header-item="delete"]', 'Delete');
});
