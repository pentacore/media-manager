<?php

declare(strict_types=1);

use App\Enums\WhisparrVersion;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Models\NotificationDestination;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;

/*
 * Every listed dialog fits a phone screen or scrolls inside it, in portrait
 * (375x812) and turned sideways (812x375, wider than `sm` but under the
 * `short:` variant's 500 px): the dialog stays on screen, its last control
 * (the footer) can be scrolled into view inside the dialog, its close X stays
 * on screen, and nothing in it overflows, overlaps or scrolls by a sliver.
 */

/**
 * @return array<string, mixed>
 */
function landscapeDialogSeries(int $id, string $title): array
{
    return [
        'id' => $id, 'title' => $title, 'titleSlug' => sprintf('example-%d', $id), 'year' => 2024, 'status' => 'continuing',
        'monitored' => true, 'qualityProfileId' => 1, 'seasons' => [], 'network' => 'Example Network',
        'images' => [['coverType' => 'poster', 'remoteUrl' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7']],
        'statistics' => ['sizeOnDisk' => 8_000_000_000, 'episodeCount' => 6, 'episodeFileCount' => 4],
    ];
}

/**
 * The services and rows the listed dialogs read: Sonarr (series 1 and 2 in
 * the library, series 7's detail), Radarr (movie 10 with three releases),
 * Whisparr (scene 11), Seerr (request 1, a TV request the edit dialog loads),
 * SABnzbd, the OpenRouter pricing feed (preflight P3), two AI usage records
 * (ids 901 and 902), an AI price and a Discord destination.
 */
function landscapeDialogFakes(): void
{
    foreach (['models_dev', 'litellm', 'xai'] as $source) {
        config()->set(sprintf('mediamanager.ai.pricing.%s.enabled', $source), false);
    }

    config()->set('mediamanager.ai.pricing.openrouter.enabled', true);
    config()->set('mediamanager.ai.pricing.openrouter.retries', 0);

    ServiceConnection::factory()->sonarr()->create(['name' => 'Sonarr', 'url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    ServiceConnection::factory()->radarr()->create(['name' => 'Radarr', 'url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    ServiceConnection::factory()->sabnzbd()->create(['name' => 'SABnzbd', 'url' => 'http://sabnzbd.local:8080', 'api_key' => 'k']);
    ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V3)->create(['name' => 'Whisparr', 'url' => 'http://whisparr.local:6969', 'api_key' => 'k']);
    ServiceConnection::factory()->seerr()->create(['name' => 'Seerr', 'url' => 'http://seerr.local:5055', 'api_key' => 'k']);

    $poster = [['coverType' => 'poster', 'remoteUrl' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7']];

    $releases = [];
    foreach (range(1, 3) as $index) {
        $releases[] = [
            'guid' => 'release-'.$index, 'indexerId' => 1, 'indexer' => 'ExampleIndexer', 'title' => 'Severance.S01E0'.$index.'.2160p.WEB-DL.DDP5.1.Atmos.DV.HDR.H.265-EXAMPLEGROUP',
            'size' => 4_500_000_000, 'age' => $index, 'ageHours' => 30, 'protocol' => $index % 2 ? 'usenet' : 'torrent', 'seeders' => 12, 'leechers' => 3,
            'quality' => ['quality' => ['name' => 'WEBDL-2160p']], 'qualityWeight' => 100, 'rejected' => $index % 3 === 0, 'rejections' => $index % 3 === 0 ? ['Example rejection reason that is fairly long'] : [],
            'customFormatScore' => 10, 'languages' => [['name' => 'English']], 'downloadAllowed' => true,
        ];
    }

    $slots = [];
    foreach (range(1, 3) as $index) {
        $slots[] = ['nzo_id' => 'SABnzbd_nzo_'.$index, 'filename' => 'Severance.S01E0'.$index.'.1080p.WEB-DL.DDP5.1.H.264-EXAMPLEGROUP', 'cat' => 'tv', 'size' => '1.0 GB', 'sizeleft' => '0.5 GB', 'percentage' => '50', 'timeleft' => '0:05:00', 'status' => 'Downloading', 'priority' => '0', 'mb' => '1024', 'mbleft' => '512'];
    }

    $seerrRequest = ['id' => 1, 'status' => 1, 'type' => 'tv', 'media' => ['mediaType' => 'tv', 'tmdbId' => 95396, 'tvdbId' => 371980, 'status' => 3],
        'requestedBy' => ['id' => 7, 'displayName' => 'Example Requester With A Long Name', 'email' => 'requester@example.com'], 'createdAt' => '2026-10-01T00:00:00Z', 'seasons' => [['seasonNumber' => 1]]];

    Http::fake([
        'openrouter.ai/*' => Http::response((string) file_get_contents(base_path('tests/Fixtures/OpenRouter/models.json'))),
        'sonarr.local:8989/api/v3/series' => Http::response([
            landscapeDialogSeries(1, 'Severance'),
            landscapeDialogSeries(2, 'The Extraordinarily Long Example Series Title Of Doom'),
        ]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p'], ['id' => 2, 'name' => 'An Example Quality Profile With A Long Name']]),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([['id' => 1, 'path' => '/data/tv', 'freeSpace' => 100_000_000_000], ['id' => 2, 'path' => '/data/anime-with-a-long-example-folder-name', 'freeSpace' => 100_000_000_000]]),
        'sonarr.local:8989/api/v3/series/7*' => Http::response(array_merge(landscapeDialogSeries(7, 'Severance'), [
            'overview' => 'An example overview.',
            'seasons' => [['seasonNumber' => 1, 'monitored' => true, 'statistics' => ['episodeCount' => 1, 'episodeFileCount' => 1, 'totalEpisodeCount' => 1, 'sizeOnDisk' => 1_000_000_000]]],
        ])),
        'sonarr.local:8989/api/v3/episode*' => Http::response([
            ['id' => 71, 'seriesId' => 7, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'An Example Episode Title That Runs Long', 'airDate' => '2022-02-18', 'hasFile' => true, 'monitored' => true],
        ]),
        'sonarr.local:8989/*' => Http::response([]),
        'radarr.local:7878/api/v3/movie' => Http::response([
            ['id' => 10, 'title' => 'An Extremely Long Example Movie Title That Wraps Badly', 'titleSlug' => 'example-10', 'year' => 2021, 'monitored' => true, 'hasFile' => false, 'qualityProfileId' => 1, 'sizeOnDisk' => 0, 'images' => $poster],
        ]),
        'radarr.local:7878/api/v3/movie/10*' => Http::response(['id' => 10, 'title' => 'An Extremely Long Example Movie Title That Wraps Badly', 'titleSlug' => 'example-10', 'year' => 2021, 'monitored' => true, 'hasFile' => true, 'qualityProfileId' => 1, 'sizeOnDisk' => 12_000_000_000, 'images' => $poster, 'status' => 'released']),
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'radarr.local:7878/api/v3/release*' => Http::response($releases),
        'radarr.local:7878/*' => Http::response([]),
        'whisparr.local:6969/api/v3/movie/11*' => Http::response(['id' => 11, 'title' => 'Example Scene Title That Runs Long Enough To Wrap On A Phone', 'year' => 2024, 'monitored' => true, 'hasFile' => false, 'sizeOnDisk' => 0, 'qualityProfileId' => 1, 'images' => []]),
        'whisparr.local:6969/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'Any']]),
        'whisparr.local:6969/api/v3/movie*' => Http::response([['id' => 11, 'title' => 'Example Scene Title', 'year' => 2024, 'monitored' => true, 'hasFile' => false, 'sizeOnDisk' => 0, 'qualityProfileId' => 1, 'images' => []]]),
        'whisparr.local:6969/*' => Http::response([]),
        'seerr.local:5055/api/v1/request/count' => Http::response(['total' => 1, 'pending' => 1, 'approved' => 0, 'declined' => 0, 'movie' => 0, 'tv' => 1]),
        // The edit dialog's options (media.requests.edit-options) read request 1 by id.
        'seerr.local:5055/api/v1/request/1' => Http::response($seerrRequest + ['profileId' => 1, 'rootFolder' => '/data/tv', 'serverId' => 0, 'is4k' => false]),
        'seerr.local:5055/api/v1/request*' => Http::response(['pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 1], 'results' => [$seerrRequest]]),
        'seerr.local:5055/api/v1/tv/*' => Http::response(['id' => 95396, 'name' => 'The Extraordinarily Long Example Series Title Of Doom', 'posterPath' => '/example.jpg', 'firstAirDate' => '2022-02-17']),
        'seerr.local:5055/api/v1/user*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1], 'results' => [['id' => 7, 'email' => 'requester@example.com', 'displayName' => 'Example Requester']]]),
        'seerr.local:5055/*' => Http::response([]),
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
    ]);

    // The usage detail's widest state: every token tier in the millions on a
    // priced model, so the cost breakdown has its longest numbers.
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini', 'input_per_mtok' => 12.5, 'output_per_mtok' => 75, 'cache_read_per_mtok' => 1.25, 'cache_write_per_mtok' => 15.625, 'reasoning_per_mtok' => 75]);
    AiUsageRecord::factory()->create(['id' => 901, 'prompt_text' => 'Find the example series and tell me about it.', 'is_benchmark' => false,
        'prompt_tokens' => 1_000_000, 'completion_tokens' => 500_000, 'cache_read_input_tokens' => 400_000, 'cache_write_input_tokens' => 250_000, 'reasoning_tokens' => 120_000]);
    AiUsageRecord::factory()->create(['id' => 902, 'prompt_text' => 'Decide.', 'is_benchmark' => false]);
    NotificationDestination::factory()->discord()->create();
}

/**
 * Clicks $clicks in order, each once it is in the DOM. $clicks is either one
 * list, or a `phone` list (below 640 px, where the series and movie actions
 * sit in the PageHeader ⋯ menu) and a `wide` list.
 *
 * @param  list<string>|array{phone: list<string>, wide: list<string>}  $clicks
 */
function landscapeDialogOpen(AwaitableWebpage|PendingAwaitablePage|Webpage $webpage, array $clicks, int $width): void
{
    $selectors = array_key_exists('phone', $clicks) ? ($width < 640 ? $clicks['phone'] : $clicks['wide']) : $clicks;

    foreach ($selectors as $selector) {
        $webpage->script(confirmDialogWaitUntilScript(sprintf('!!document.querySelector(%s)', json_encode($selector, JSON_THROW_ON_ERROR))));
        $webpage->click($selector);
    }
}

/**
 * 'ok', or the first way the open dialog fails a phone: its box leaves the
 * screen; scrolled to its end, its last control (the footer, not a row of an
 * inner list) is not inside the dialog; or its close X leaves the screen or
 * the dialog. A dialog without a footer (every control in an inner list) has
 * its inner lists scrolled to their end too, and their last control checked.
 * A control inside a sideways-scrolling frame is checked vertically only.
 */
function landscapeDialogReachScript(): string
{
    return <<<'JS'
        (() => {
            const dialog = document.querySelector('[data-slot="dialog-content"]');
            if (!dialog) {
                return 'no dialog is open';
            }
            const viewport = { left: 0, top: 0, right: window.innerWidth, bottom: window.innerHeight };
            const inside = (rect, box) => rect.left >= box.left - 1 && rect.right <= box.right + 1 && rect.top >= box.top - 1 && rect.bottom <= box.bottom + 1;
            const round = (rect) => `${Math.round(rect.left)},${Math.round(rect.top)}..${Math.round(rect.right)},${Math.round(rect.bottom)}`;
            const scrolls = (el) => ['auto', 'scroll'].includes(getComputedStyle(el).overflowY) || ['auto', 'scroll'].includes(getComputedStyle(el).overflowX);
            const visuallyHidden = (el) => el.style.position === 'absolute' && el.style.width === '1px' && el.style.height === '1px';

            const box = dialog.getBoundingClientRect();
            if (!inside(box, viewport)) {
                return `the dialog ${round(box)} leaves the ${window.innerWidth}x${window.innerHeight} screen`;
            }

            const body = dialog.querySelector('[data-slot="dialog-body"]');
            const scroller = body && scrolls(body) ? body : dialog;
            scroller.scrollTop = scroller.scrollHeight;

            // An inner list scrolls on its own (it has more than it shows); a
            // frame that only scrolls sideways (a wide table) does not count.
            const scrollsDown = (el) => ['auto', 'scroll'].includes(getComputedStyle(el).overflowY) && el.scrollHeight > el.clientHeight;
            const nearestScroller = (el) => {
                for (let node = el.parentElement; node; node = node.parentElement) {
                    if (node === scroller || scrollsDown(node)) {
                        return node;
                    }
                }
                return null;
            };
            const insideSidewaysFrame = (el) => {
                for (let node = el.parentElement; node && node !== scroller; node = node.parentElement) {
                    if (['auto', 'scroll'].includes(getComputedStyle(node).overflowX)) {
                        return true;
                    }
                }
                return false;
            };
            const visible = (el) => {
                const rect = el.getBoundingClientRect();
                if (rect.width === 0 || rect.height === 0 || getComputedStyle(el).visibility !== 'visible' || el.closest('.sr-only, [aria-hidden="true"]')) {
                    return false;
                }
                for (let node = el; node && node !== dialog; node = node.parentElement) {
                    if (visuallyHidden(node)) {
                        return false;
                    }
                }
                return true;
            };
            const all = [...dialog.querySelectorAll('button, a[href], input, select, textarea, [role="combobox"]')]
                .filter((el) => !el.closest('[data-slot="dialog-close"]') && visible(el));
            let controls = all.filter((el) => nearestScroller(el) === scroller);
            if (controls.length === 0) {
                // No footer: every control sits in an inner list (the
                // interactive search table once it shrinks to a scroller).
                // Scroll each inner list to its end too; its last row must
                // then show inside the dialog.
                for (const inner of dialog.querySelectorAll('*')) {
                    if (scrollsDown(inner)) {
                        inner.scrollTop = inner.scrollHeight;
                    }
                }
                controls = all;
            }
            const last = controls[controls.length - 1];
            if (!last) {
                return 'the dialog has no visible control';
            }
            // A control in a sideways-scrolling frame is reached by scrolling
            // that frame, so only its vertical place is checked.
            const lastRect = last.getBoundingClientRect();
            const dialogRect = dialog.getBoundingClientRect();
            const reachable = insideSidewaysFrame(last)
                ? lastRect.top >= dialogRect.top - 1 && lastRect.bottom <= dialogRect.bottom + 1
                : inside(lastRect, dialogRect);
            if (!reachable) {
                const name = (last.innerText || last.getAttribute('aria-label') || last.tagName).trim().slice(0, 40);
                return `last control unreachable: "${name}" ${round(lastRect)} outside the dialog ${round(dialog.getBoundingClientRect())}`;
            }

            const close = dialog.querySelector('[data-slot="dialog-close"]');
            if (close) {
                const closeRect = close.getBoundingClientRect();
                if (!inside(closeRect, viewport) || !inside(closeRect, dialog.getBoundingClientRect())) {
                    return `close X off screen: ${round(closeRect)}`;
                }
            }

            return 'ok';
        })()
    JS;
}

test('every listed dialog fits or scrolls on a phone, portrait and landscape', function (string $routeName, array $parameters, array $clicks, ?string $ready, int $width, int $height): void {
    config()->set('mediamanager.ai.enabled', true);
    landscapeDialogFakes();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route($routeName, $parameters, absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->resize($width, $height);
    $webpage->script(appHydratedScript());
    $webpage->script(confirmDialogWaitUntilScript('!document.querySelector("main [data-slot=\"skeleton\"]")'));
    landscapeDialogOpen($webpage, $clicks, $width);

    $open = '!!document.querySelector("[data-slot=\"dialog-content\"]") && document.querySelector("[data-slot=\"dialog-content\"]").getAnimations().length === 0 && !document.querySelector("[data-slot=\"dialog-content\"] [data-slot=\"skeleton\"]")';
    $condition = $ready === null ? $open : "{$open} && {$ready}";
    $webpage->script(confirmDialogWaitUntilScript($condition));
    $webpage->assertScript($condition, true);

    $webpage->assertScript(phoneOverflowScript('[role="dialog"]'), '')
        ->assertScript(phoneScrollSliverScript('[role="dialog"]'), '')
        ->assertScript(phoneOverlapScript('[role="dialog"]'), '')
        ->assertScript(landscapeDialogReachScript(), 'ok');
})->with([
    'users invite' => ['admin.users.index', [], ['[data-users-invite]'], null],
    'new ai price' => ['admin.ai-prices.index', [], ['[data-create-price]'], null],
    'ai price from catalog' => ['admin.ai-prices.index', [], ['[data-add-from-catalog]'], '!document.querySelector("[data-catalog-loading]") && !!document.querySelector("[data-catalog-row]")'],
    'new server' => ['admin.servers.index', [], ['[data-server-add]'], null],
    'new notification destination' => ['admin.notification-destinations.index', [], ['[data-destination-add]'], null],
    'ai usage detail' => ['admin.ai-usage.index', [], ['[data-usage-row="901"]'], '!!document.querySelector("[data-usage-detail]") && !document.querySelector("[data-usage-detail]").textContent.includes("Loading…")'],
    'request edit' => ['media.requests.index', [], ['[data-request-edit]'], '!!document.querySelector("[data-request-edit-options]")'],
    'series delete' => ['media.series.show', ['id' => 7], ['phone' => ['[data-page-header-more]', '[data-page-header-item="delete"]'], 'wide' => ['[data-delete-trigger]']], '!!document.querySelector("[data-delete-description]")'],
    'interactive search' => ['media.movies.show', ['id' => 10], ['phone' => ['[data-page-header-more]', '[data-page-header-item="interactive-search"]'], 'wide' => ['[data-interactive-search]']], 'document.querySelectorAll("[data-interactive-search-dialog] [data-release-row], [data-interactive-search-dialog] [data-release-card]").length > 0'],
    'whisparr delete' => ['media.whisparr.show', ['id' => 11], ['[data-delete-trigger]'], null],
    // Preflight P11: the faked Sonarr library is ids 1 and 2.
    'library bulk delete' => ['media.series.index', [], ['[data-bulk-select="1"]', '[data-bulk-action="delete"]'], null],
    // Preflight P14: the trigger opens a menu; the dialog sits behind "Custom…".
    'sabnzbd speed limit' => ['sabnzbd.queue.index', [], ['[data-speed-limit-trigger]', '[data-speed-limit-custom]'], null],
])->with([
    'portrait' => [375, 812],
    'landscape' => [812, 375],
]);

test('the AI usage cost breakdown fits a phone-width dialog without scrolling sideways', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    landscapeDialogFakes();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.ai-usage.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    landscapeDialogOpen($webpage, ['[data-usage-row="901"]'], 375);
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-usage-breakdown]")'));

    // Record 901's widest state (every tier in the millions, priced): the
    // breakdown's phone cell padding keeps its columns inside the frame.
    $webpage->assertScript('(() => { const frame = document.querySelector("[data-usage-breakdown] > div"); return frame.scrollWidth <= frame.clientWidth; })()', true);
});
