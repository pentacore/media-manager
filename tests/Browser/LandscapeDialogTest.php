<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\WhisparrVersion;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Models\NotificationDestination;
use App\Models\Server;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Settings\MediaReplacementSettings;
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
 * (ids 901 and 902), an AI price and a Discord destination. For the dialogs
 * added in Task 13: a stuck Sonarr queue item (id 41) with three manual-import
 * candidates (one a sample, one unmapped), movie 10's file and history for the
 * replace-file dialog, a server (id 901) and two benchmark runs (901 and 902)
 * over one case whose results (901 and 902) differ, with long outputs.
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
            'guid' => 'release-'.$index, 'indexerId' => 1, 'indexer' => 'ExampleIndexer', 'movieId' => 10, 'title' => 'Severance.S01E0'.$index.'.2160p.WEB-DL.DDP5.1.Atmos.DV.HDR.H.265-EXAMPLEGROUP',
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
        'sonarr.local:8989/api/v3/queue*' => Http::response(['records' => [[
            'id' => 41, 'title' => 'The.Extraordinarily.Long.Example.Series.Title.Of.Doom.S01E01.1080p.WEB-DL-EXAMPLEGROUP', 'series' => ['title' => 'The Extraordinarily Long Example Series Title Of Doom'],
            'episode' => ['seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Pilot'], 'status' => 'completed', 'trackedDownloadStatus' => 'warning', 'trackedDownloadState' => 'importPending',
            'protocol' => 'usenet', 'downloadClient' => 'SABnzbd', 'size' => 1_000_000_000, 'sizeleft' => 0, 'timeleft' => '00:00:00',
            'statusMessages' => [['title' => 'Example status', 'messages' => ['No files found are eligible for import']]], 'added' => '2026-09-29T10:00:00Z',
            'quality' => ['quality' => ['name' => 'WEBDL-1080p']], 'downloadId' => 'SABnzbd_nzo_41',
        ]]]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => [], 'totalRecords' => 0]),
        'sonarr.local:8989/api/v3/manualimport*' => Http::response([
            [
                'path' => '/downloads/The.Extraordinarily.Long.Example.Series.Title.Of.Doom.S01E01.1080p.WEB-DL-EXAMPLEGROUP/episode.mkv', 'name' => 'The.Extraordinarily.Long.Example.Series.Title.Of.Doom.S01E01', 'size' => 1_000_000_000,
                'series' => ['id' => 2, 'title' => 'The Extraordinarily Long Example Series Title Of Doom'], 'seasonNumber' => 1,
                'episodes' => [['id' => 555, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'An Example Episode Title That Runs Long']],
                'quality' => ['quality' => ['id' => 4, 'name' => 'WEBDL-1080p']], 'languages' => [['id' => 1, 'name' => 'English']], 'releaseType' => 'singleEpisode', 'rejections' => [],
            ],
            [
                'path' => '/downloads/Sample/the-extraordinarily-long-example-series-sample.mkv', 'name' => 'sample', 'size' => 20_000_000,
                'series' => ['id' => 2, 'title' => 'The Extraordinarily Long Example Series Title Of Doom'], 'seasonNumber' => 1,
                'episodes' => [['id' => 555, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'An Example Episode Title That Runs Long']],
                'quality' => ['quality' => ['id' => 4, 'name' => 'WEBDL-1080p']], 'languages' => [['id' => 1, 'name' => 'English']], 'releaseType' => 'singleEpisode', 'rejections' => [['reason' => 'Sample', 'type' => 'permanent']],
            ],
            [
                'path' => '/downloads/Extras/an-example-featurette-with-a-long-file-name.mkv', 'name' => 'featurette', 'size' => 300_000_000,
                'quality' => ['quality' => ['id' => 4, 'name' => 'WEBDL-1080p']], 'languages' => [['id' => 1, 'name' => 'English']], 'rejections' => [['reason' => 'Unknown Series', 'type' => 'permanent']],
            ],
        ]),
        'sonarr.local:8989/*' => Http::response([]),
        'radarr.local:7878/api/v3/movie' => Http::response([
            ['id' => 10, 'title' => 'An Extremely Long Example Movie Title That Wraps Badly', 'titleSlug' => 'example-10', 'year' => 2021, 'monitored' => true, 'hasFile' => false, 'qualityProfileId' => 1, 'sizeOnDisk' => 0, 'images' => $poster],
        ]),
        'radarr.local:7878/api/v3/movie/10*' => Http::response(['id' => 10, 'title' => 'An Extremely Long Example Movie Title That Wraps Badly', 'titleSlug' => 'example-10', 'year' => 2021, 'monitored' => true, 'hasFile' => true, 'qualityProfileId' => 1, 'sizeOnDisk' => 12_000_000_000, 'images' => $poster, 'status' => 'released',
            'movieFileId' => 5, 'movieFile' => ['quality' => ['quality' => ['name' => 'Bluray-1080p']], 'size' => 12_000_000_000, 'relativePath' => 'An Extremely Long Example Movie Title That Wraps Badly (2021)/An.Extremely.Long.Example.Movie.Title.That.Wraps.Badly.2021.1080p.BluRay.mkv']]),
        'radarr.local:7878/api/v3/moviefile/5' => Http::response(['id' => 5, 'movieId' => 10, 'sceneName' => 'An.Extremely.Long.Example.Movie.Title.That.Wraps.Badly.2021.1080p.BluRay', 'releaseGroup' => 'EXAMPLEGROUP', 'quality' => ['quality' => ['name' => 'Bluray-1080p']], 'mediaInfo' => ['subtitles' => 'English / Swedish']]),
        'radarr.local:7878/api/v3/history*' => Http::response(['records' => [['id' => 777, 'eventType' => 'grabbed', 'movieId' => 10, 'downloadId' => 'XYZ']]]),
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
    Server::factory()->create(['id' => 901, 'name' => 'example-host-with-a-long-name-01']);
    // The replace-file search ranks only releases with subtitle evidence for a
    // required language: a guarantee rule matches the fake releases' group.
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_enabled' => false, 'automatic_selection_threshold' => 90, 'global_languages' => ['English'],
        'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null], 'season_pack_policy' => 'approval_required',
        'guidance' => ['anime' => ['notes' => '', 'rules' => []], 'tv' => ['notes' => '', 'rules' => []], 'movie' => ['notes' => '', 'rules' => [[
            'name' => 'Example group carries English', 'enabled' => true, 'strength' => 'guarantee', 'languages' => ['English'], 'conditions' => [['field' => 'title', 'value' => 'EXAMPLEGROUP']],
        ]]]],
    ]);

    $candidates = [['provider' => 'openai', 'model' => 'gpt-5-mini', 'reasoning' => null]];
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create(['id' => 901, 'name' => 'Example case about a director', 'input' => ['prompt' => 'Which films did the example director make?']]);
    foreach ([901 => ['Example baseline run', 0.9, true], 902 => ['Example rerun', 0.3, false]] as $id => [$name, $score, $passed]) {
        $benchmarkRun = BenchmarkRun::factory()->completed()->withCandidates($candidates)->create(['id' => $id, 'name' => $name, 'judge' => ['provider' => 'openai', 'model' => 'gpt-judge', 'reasoning' => null]]);
        BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->scored($score, $passed)->create([
            'id' => $id, 'latency_ms' => 500, 'completion_tokens' => 50,
            'output' => str_repeat('The example director made several films that run long in this example answer. ', 6).'/data/media/movies/An.Extremely.Long.Example.Movie.Title.That.Wraps.Badly.2021.1080p.BluRay.mkv',
            'score_detail' => ['score_10' => (int) round($score * 10), 'rationale' => str_repeat('An example verdict that explains the score at some length. ', 4), 'tool_log' => []],
        ]);
    }
}

/**
 * Asserts the open dialog (once $ready holds too, when given) stays on the
 * screen and inside it: nothing overflows, scrolls by a sliver or overlaps,
 * and its last control and close X can be reached.
 */
function landscapeDialogAssertOpen(AwaitableWebpage|PendingAwaitablePage|Webpage $webpage, ?string $ready): void
{
    $open = '!!document.querySelector("[data-slot=\"dialog-content\"]") && document.querySelector("[data-slot=\"dialog-content\"]").getAnimations().length === 0 && !document.querySelector("[data-slot=\"dialog-content\"] [data-slot=\"skeleton\"]")';
    $condition = $ready === null ? $open : "{$open} && {$ready}";
    $webpage->script(confirmDialogWaitUntilScript($condition));
    $webpage->assertScript($condition, true);

    $webpage->assertScript(phoneOverflowScript('[role="dialog"]'), '')
        ->assertScript(phoneScrollSliverScript('[role="dialog"]'), '')
        ->assertScript(phoneOverlapScript('[role="dialog"]'), '')
        ->assertScript(landscapeDialogReachScript(), 'ok');
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
 * its inner lists scrolled to their end too, and their last control checked;
 * a dialog with no control at all has its last content block checked.
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
            // A read-only dialog (two outputs side by side) has no control
            // but its close X: its last block must then show instead.
            const blocks = [...(scroller === dialog ? dialog.children : scroller.children)].filter((el) => !el.closest('[data-slot="dialog-close"]') && visible(el));
            const lastIsBlock = controls.length === 0;
            const last = controls[controls.length - 1] ?? blocks[blocks.length - 1];
            if (!last) {
                return 'the dialog has no visible control or content';
            }
            // A control in a sideways-scrolling frame is reached by scrolling
            // that frame, so only its vertical place is checked.
            const lastRect = last.getBoundingClientRect();
            const dialogRect = dialog.getBoundingClientRect();
            // A content block can be taller than the dialog: its end must show.
            const reachable = lastIsBlock
                ? lastRect.bottom <= dialogRect.bottom + 1
                : insideSidewaysFrame(last)
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

    landscapeDialogAssertOpen($webpage, $ready);
})->with([
    'users invite' => ['admin.users.index', [], ['[data-users-invite]'], null],
    'new ai price' => ['admin.ai-prices.index', [], ['[data-create-price]'], null],
    'ai price from catalog' => ['admin.ai-prices.index', [], ['[data-add-from-catalog]'], '!document.querySelector("[data-catalog-loading]") && !!document.querySelector("[data-catalog-row]")'],
    'new server' => ['admin.servers.index', [], ['[data-server-add]'], null],
    'new notification destination' => ['admin.notification-destinations.index', [], ['[data-destination-add]'], null],
    // A what-if price scenario adds the breakdown's Scenario column, its widest state.
    'ai usage detail' => ['admin.ai-usage.index', ['scenario' => landscapeDialogScenario()], ['[data-usage-row="901"]'], '!!document.querySelector("[data-usage-detail]") && !document.querySelector("[data-usage-detail]").textContent.includes("Loading…") && document.querySelector("[data-usage-breakdown]").textContent.includes("Scenario")'],
    'request edit' => ['media.requests.index', [], ['[data-request-edit]'], '!!document.querySelector("[data-request-edit-options]")'],
    'series delete' => ['media.series.show', ['id' => 7], ['phone' => ['[data-page-header-more]', '[data-page-header-item="delete"]'], 'wide' => ['[data-delete-trigger]']], '!!document.querySelector("[data-delete-description]")'],
    'interactive search' => ['media.movies.show', ['id' => 10], ['phone' => ['[data-page-header-more]', '[data-page-header-item="interactive-search"]'], 'wide' => ['[data-interactive-search]']], 'document.querySelectorAll("[data-interactive-search-dialog] [data-release-row], [data-interactive-search-dialog] [data-release-card]").length > 0'],
    'whisparr delete' => ['media.whisparr.show', ['id' => 11], ['[data-delete-trigger]'], null],
    // Preflight P11: the faked Sonarr library is ids 1 and 2.
    'library bulk delete' => ['media.series.index', [], ['[data-bulk-select="1"]', '[data-bulk-action="delete"]'], null],
    // Preflight P14: the trigger opens a menu; the dialog sits behind "Custom…".
    'sabnzbd speed limit' => ['sabnzbd.queue.index', [], ['[data-speed-limit-trigger]', '[data-speed-limit-custom]'], null],
    'server enrollment' => ['admin.servers.show', ['server' => 901], ['phone' => ['[data-page-header-more]', '[data-page-header-item="regenerate"]'], 'wide' => ['[data-server-regenerate]']], '!!document.querySelector("[data-enrollment-dialog]")'],
    // The app-wide useConfirm() dialog, asked before a server is removed.
    'confirm' => ['admin.servers.show', ['server' => 901], ['phone' => ['[data-page-header-more]', '[data-page-header-item="delete"]'], 'wide' => ['[data-server-delete]']], '!!document.querySelector("[data-confirm-accept]")'],
    // The widest state: the release search ran and lists its candidates.
    'replace file' => ['media.movies.show', ['id' => 10], ['phone' => ['[data-page-header-more]', '[data-page-header-item="replace"]', '[data-replacement-search]'], 'wide' => ['[data-replacement-trigger]', '[data-replacement-search]']], '!!document.querySelector("[data-replacement-candidate-row]")'],
    'compare outputs' => ['admin.benchmarks.compare.show', ['runs' => '901,902'], ['[data-compare-vs-baseline="901:902"]'], '!!document.querySelector("[data-compare-outputs] [data-compare-output-text]")'],
    // Three candidates: one to import, a sample and an unmapped file.
    'activity import' => ['media.library.activity.queue', [], ['phone' => ['[data-queue-card-menu="sonarr-41"]', '[data-queue-manual-import]'], 'wide' => ['[data-queue-row="sonarr-41"] [data-queue-row-menu]', '[data-queue-manual-import]']], 'document.querySelectorAll("[data-manual-import-candidate]").length === 3'],
])->with([
    'portrait' => [375, 812],
    'landscape' => [812, 375],
]);

/**
 * A what-if price for every tier, high enough to give the Scenario column
 * its longest costs.
 *
 * @return array{input: float, output: float, cache_read: float, cache_write: float, reasoning: float}
 */
function landscapeDialogScenario(): array
{
    return ['input' => 999.99, 'output' => 9999.99, 'cache_read' => 999.99, 'cache_write' => 999.99, 'reasoning' => 9999.99];
}

test('the AI usage cost breakdown fits a phone-width dialog without scrolling sideways', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    landscapeDialogFakes();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.ai-usage.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    landscapeDialogOpen($webpage, ['[data-usage-row="901"]'], 375);
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-usage-breakdown]")'));

    // Record 901's widest four-column state (every tier in the millions,
    // priced): the breakdown's phone cell padding keeps its columns inside
    // the frame. A what-if scenario's fifth Scenario column scrolls inside
    // the frame by design; the 'ai usage detail' rows above measure that.
    $webpage->assertScript('(() => { const frame = document.querySelector("[data-usage-breakdown] > div"); return frame.scrollWidth <= frame.clientWidth; })()', true);
});

/**
 * The two-factor setup dialog, reached through password confirmation: its
 * QR code step, then its code step.
 */
test('the two-factor setup dialog fits or scrolls on a phone, portrait and landscape', function (int $width, int $height): void {
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('security.edit', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->resize($width, $height);
    $webpage->script(appHydratedScript());
    $webpage->fill('[data-confirm-password-input]', 'password')
        ->click('[data-test="confirm-password-button"]');
    $webpage->assertPathIs(route('security.edit', absolute: false));
    $webpage->script(appHydratedScript());
    $webpage->click('[data-two-factor-enable]');

    // The QR code and the setup key have loaded.
    landscapeDialogAssertOpen($webpage, '!document.querySelector("[data-slot=\\"dialog-content\\"] .animate-pulse") && !!document.querySelector("[data-slot=\\"dialog-content\\"] input")');

    $webpage->click('[data-two-factor-continue]');
    landscapeDialogAssertOpen($webpage, '!!document.querySelector("[data-slot=\\"dialog-content\\"] [data-input-otp]")');
})->with([
    'portrait' => [375, 812],
    'landscape' => [812, 375],
]);
