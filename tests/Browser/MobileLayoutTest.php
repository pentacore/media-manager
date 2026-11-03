<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\AiUsageKind;
use App\Enums\BazarrServiceRole;
use App\Enums\SecondOpinionMode;
use App\Enums\SubtitleCaseStatus;
use App\Enums\WhisparrVersion;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\AgentDecision;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Models\AnimeIdMap;
use App\Models\BazarrServiceLink;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSchedule;
use App\Models\ChatTemplate;
use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use App\Models\IndexedSeries;
use App\Models\MediaReplacementAttempt;
use App\Models\NotificationDestination;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Models\ServerIncident;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Settings\BazarrAutomationSettings;
use App\Settings\DecisionAgentSettings;
use App\Settings\MediaReplacementSettings;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
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
 * route` fails while a named GET route in the `web` group (behind `auth`,
 * behind `guest` or public) is in no dataset and not in
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

/**
 * @param  array<string, mixed>  $overrides  fakes matched before the defaults (a row's own worst case)
 */
function mobileLayoutFakeServices(array $overrides = []): void
{
    // The id sequence outlives RefreshDatabase's rollback, and a whole run hands
    // out ~10 connection ids per test: keep it past the fixed ids 901 and 902
    // from the first test on, so no factory row takes one and every connection
    // made here has a higher id than the admin rows' Sonarr 902, whatever the
    // test order.
    if (DB::getDriverName() === 'pgsql') {
        DB::statement("SELECT setval('service_connections_id_seq', GREATEST((SELECT last_value FROM service_connections_id_seq), 902))");
    }

    $poster = [['coverType' => 'poster', 'remoteUrl' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7']];
    $sonarr = ServiceConnection::factory()->sonarr()->create(['name' => 'Sonarr', 'url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    ServiceConnection::factory()->radarr()->create(['name' => 'Radarr', 'url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    ServiceConnection::factory()->sabnzbd()->create(['name' => 'SABnzbd', 'url' => 'http://sabnzbd.local:8080', 'api_key' => 'k']);
    ServiceConnection::factory()->emby()->create(['name' => 'Emby', 'url' => 'http://emby.local:8096', 'api_key' => 'k']);
    ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V3)->create(['name' => 'Whisparr', 'url' => 'http://whisparr.local:6969', 'api_key' => 'k']);
    ServiceConnection::factory()->seerr()->create(['name' => 'Seerr', 'url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    // Indexer search reads it; the admin connection rows edit it by id.
    ServiceConnection::factory()->prowlarr()->create(['id' => 901, 'name' => 'Prowlarr', 'url' => 'http://prowlarr.local:9696', 'api_key' => 'k']);

    // Indexer search results with long scene names; the search is admin-grabbable.
    $releases = [];
    foreach (range(1, 3) as $index) {
        $releases[] = [
            'guid' => 'https://tracker.example/download/'.$index, 'indexerId' => 3, 'title' => 'The.Extraordinarily.Long.Example.Series.Title.Of.Doom.S02E0'.$index.'.2160p.WEB-DL.DDP5.1.Atmos.DV.HDR.H.265-EXAMPLEGROUP',
            'indexer' => 'Example Usenet Indexer', 'size' => 2_500_000_000, 'seeders' => 412, 'age' => $index * 120, 'publishDate' => now()->subDays($index)->toIso8601String(),
            'downloadUrl' => 'http://prowlarr.local:9696/3/download?link='.$index, 'infoUrl' => 'https://tracker.example/details/'.$index, 'protocol' => 'usenet', 'categories' => [['id' => 5040, 'name' => 'TV/HD']],
        ];
    }

    // Wanted: two records a side, with 3-digit totals so both pagers show.
    $wantedEpisodes = [];
    foreach ([1, 2] as $number) {
        $wantedEpisodes[] = ['id' => 80 + $number, 'seriesId' => 2, 'seasonNumber' => 1, 'episodeNumber' => $number, 'title' => 'Example Episode With A Fairly Long Name '.$number, 'airDateUtc' => '2026-02-1'.$number.'T02:00:00Z', 'hasFile' => false, 'monitored' => true, 'series' => ['title' => 'The Extraordinarily Long Example Series Title Of Doom']];
    }

    $wantedMovies = [
        ['id' => 11, 'title' => 'An Extremely Long Example Movie Title That Wraps Badly', 'titleSlug' => 'example-11', 'year' => 2021, 'monitored' => true, 'hasFile' => false, 'qualityProfileId' => 1, 'images' => $poster, 'digitalRelease' => '2026-01-01T00:00:00Z'],
        ['id' => 13, 'title' => 'Dune', 'titleSlug' => 'example-13', 'year' => 2021, 'monitored' => true, 'hasFile' => false, 'qualityProfileId' => 1, 'images' => $poster],
    ];
    // Add series / Add movie lookups: one long title, one with no poster.
    $lookupOverview = 'An example overview of a fictional title, long enough to fill the three lines the card clamps it to on a phone screen and then some more.';

    $slots = [];
    foreach (range(1, 3) as $index) {
        $slots[] = ['nzo_id' => 'SABnzbd_nzo_'.$index, 'filename' => 'Severance.S01E0'.$index.'.1080p.WEB-DL.DDP5.1.H.264-EXAMPLEGROUP', 'cat' => 'tv', 'size' => '1.0 GB', 'sizeleft' => '0.5 GB', 'percentage' => '50', 'timeleft' => '0:05:00', 'status' => 'Downloading', 'priority' => '0', 'mb' => '1024', 'mbleft' => '512'];
    }

    Http::fake([
        ...$overrides,
        ...mobileLayoutSubtitlesAndAnimeResponses(),
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
        // The Sonarr connection's edit page: root folders, tags and disk paths.
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([['id' => 1, 'path' => '/data/tv', 'freeSpace' => 100_000_000_000], ['id' => 2, 'path' => '/data/anime-with-a-long-example-folder-name', 'freeSpace' => 100_000_000_000]]),
        'sonarr.local:8989/api/v3/tag' => Http::response([['id' => 1, 'label' => 'subtitle-check'], ['id' => 2, 'label' => 'example-tag']]),
        'sonarr.local:8989/api/v3/diskspace' => Http::response([['path' => '/data/tv', 'label' => 'TV', 'freeSpace' => 100_000_000_000, 'totalSpace' => 200_000_000_000], ['path' => '/data/anime-with-a-long-example-folder-name', 'label' => 'Anime', 'freeSpace' => 50_000_000_000, 'totalSpace' => 200_000_000_000]]),
        'sonarr.local:8989/api/v3/wanted/missing*' => Http::response(['page' => 1, 'pageSize' => 2, 'totalRecords' => 120, 'records' => $wantedEpisodes]),
        // The cutoff tab is the failed-read state: each section shows its error.
        'sonarr.local:8989/api/v3/wanted/cutoff*' => Http::response(['message' => 'Example upstream failure'], 500),
        'sonarr.local:8989/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 371980, 'title' => 'The Extraordinarily Long Example Series Title Of Doom', 'year' => 2022, 'overview' => $lookupOverview, 'remotePoster' => $poster[0]['remoteUrl']],
            ['tvdbId' => 371981, 'title' => 'Severance', 'year' => 2022, 'overview' => $lookupOverview],
        ]),
        'sonarr.local:8989/*' => Http::response([]),
        'radarr.local:7878/api/v3/movie' => Http::response([
            ['id' => 10, 'title' => 'An Extremely Long Example Movie Title That Wraps Badly', 'titleSlug' => 'example-10', 'year' => 2021, 'monitored' => true, 'hasFile' => false, 'qualityProfileId' => 1, 'sizeOnDisk' => 0, 'images' => $poster],
        ]),
        'radarr.local:7878/api/v3/queue*' => Http::response(['page' => 1, 'totalRecords' => 0, 'records' => []]),
        'radarr.local:7878/api/v3/movie/10*' => Http::response(['id' => 10, 'title' => 'An Extremely Long Example Movie Title That Wraps Badly', 'titleSlug' => 'example-10', 'year' => 2021, 'monitored' => true, 'hasFile' => true, 'qualityProfileId' => 1, 'sizeOnDisk' => 12_000_000_000, 'images' => $poster, 'status' => 'released']),
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'radarr.local:7878/api/v3/calendar*' => Http::response([]),
        'radarr.local:7878/api/v3/wanted/missing*' => Http::response(['page' => 1, 'pageSize' => 2, 'totalRecords' => 120, 'records' => $wantedMovies]),
        'radarr.local:7878/api/v3/wanted/cutoff*' => Http::response(['message' => 'Example upstream failure'], 500),
        'radarr.local:7878/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 438631, 'title' => 'An Extremely Long Example Movie Title That Wraps Badly', 'year' => 2021, 'overview' => $lookupOverview, 'remotePoster' => $poster[0]['remoteUrl']],
            ['tmdbId' => 438632, 'title' => 'Dune', 'year' => 2021, 'overview' => $lookupOverview],
        ]),
        'radarr.local:7878/*' => Http::response([]),
        'whisparr.local:6969/api/v3/movie/11*' => Http::response(['id' => 11, 'title' => 'Example Scene Title That Runs Long Enough To Wrap On A Phone', 'year' => 2024, 'monitored' => true, 'hasFile' => false, 'sizeOnDisk' => 0, 'qualityProfileId' => 1, 'images' => []]),
        'whisparr.local:6969/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'Any']]),
        'whisparr.local:6969/api/v3/movie*' => Http::response([['id' => 11, 'title' => 'Example Scene Title', 'year' => 2024, 'monitored' => true, 'hasFile' => false, 'sizeOnDisk' => 0, 'qualityProfileId' => 1, 'images' => []]]),
        'whisparr.local:6969/*' => Http::response([]),
        // The Prowlarr connection's edit page lists its indexers (mobileLayoutSeedDetailModels()).
        'prowlarr.local:9696/api/v1/indexer*' => Http::response([['id' => 3, 'name' => 'Example Usenet Indexer', 'enable' => true, 'protocol' => 'usenet', 'priority' => 25]]),
        // A query containing "failing" is the failed-search state.
        'prowlarr.local:9696/api/v1/search*' => fn (Request $request) => str_contains($request->url(), 'failing') ? Http::response(['message' => 'Example upstream failure'], 500) : Http::response($releases),
        'prowlarr.local:9696/*' => Http::response([]),
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

    // Both on a webhook event of the fakes' Sonarr: the factory default would
    // bring a connection of a random type, and a random Prowlarr (a lower id
    // than 901) would take over the indexer search.
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending, 'title' => 'Monitor series "'.str_repeat('Severance', 6).'"', 'webhook_event_id' => WebhookEvent::factory()->create(['service_connection_id' => $sonarr->id])->id]);
    ActionRequest::factory()->described()->failed()->create(['webhook_event_id' => WebhookEvent::factory()->create(['service_connection_id' => $sonarr->id])->id]);
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
    // The activity log's rows: one short, one with a long unbroken path the
    // phone row must wrap and break.
    ActivityLog::factory()->create(['action' => 'sabnzbd.queue.paused', 'description' => 'Paused the SABnzbd queue.']);
    ActivityLog::factory()->create(['action' => 'media.library.episode.imported', 'description' => 'Imported /data/media/tv/'.str_repeat('Example-Unbroken-Path-Segment-', 4).'S01E01.mkv']);
    mobileLayoutFakeSubtitlesAndAnime();
}

/**
 * The long unbroken file name the Subtitles pages must break on a phone.
 */
function mobileLayoutSubtitleFileName(): string
{
    return 'Severance.S01E01.Good.News.About.Hell.2160p.WEB-DL.DDP5.1.Atmos.DV.HDR.H.265-EXAMPLEGROUP.mkv';
}

/**
 * The AniList season page: four entries, the first with a long title.
 *
 * @return list<array<string, mixed>>
 */
function mobileLayoutAnimeEntries(): array
{
    $anime = [];
    foreach (['The Extraordinarily Long Example Anime Title That Wraps Badly', 'Example Anime Two', 'Example Anime Three', 'Short Example'] as $index => $title) {
        $anime[] = [
            'id' => 9101 + $index, 'idMal' => 9201 + $index, 'format' => 'TV', 'status' => 'RELEASING', 'episodes' => 12, 'popularity' => 5000 - $index * 100,
            'averageScore' => 80 + $index, 'title' => ['romaji' => 'Example Romaji '.($index + 1), 'english' => $title],
            'startDate' => ['year' => 2026, 'month' => 1, 'day' => 4 + $index], 'coverImage' => ['large' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'],
        ];
    }

    return $anime;
}

/**
 * The Bazarr and AniList fakes, spread into mobileLayoutFakeServices()'s one
 * Http::fake() array (Task 1's capture payloads). The episodes name the
 * gate's Sonarr series 1 and 2, which Bazarr's readers look up. Every Bazarr
 * capability is advertised, so every drawer and admin action renders.
 *
 * @return array<string, mixed>
 */
function mobileLayoutSubtitlesAndAnimeResponses(): array
{
    $longFile = mobileLayoutSubtitleFileName();
    $english = ['name' => 'English', 'code2' => 'en', 'code3' => 'eng', 'forced' => false, 'hi' => false];
    $swedish = ['name' => 'Swedish', 'code2' => 'sv', 'code3' => 'swe', 'forced' => false, 'hi' => false];
    $episodes = [
        ['sonarrSeriesId' => 1, 'sonarrEpisodeId' => 81, 'seriesTitle' => 'Severance', 'episode_number' => '1x01', 'title' => $longFile, 'episodeTitle' => $longFile, 'sceneName' => $longFile, 'monitored' => true, 'missing_subtitles' => [$swedish], 'subtitles' => [$english + ['path' => '/tv/severance/'.Str::beforeLast($longFile, '.mkv').'.en.srt']]],
        ['sonarrSeriesId' => 1, 'sonarrEpisodeId' => 82, 'seriesTitle' => 'Severance', 'episode_number' => '1x02', 'title' => 'Example Episode With A Fairly Long Name 2', 'episodeTitle' => 'Example Episode With A Fairly Long Name 2', 'sceneName' => 'Severance.S01E02.1080p.WEB-DL', 'monitored' => true, 'missing_subtitles' => [$english, $swedish], 'subtitles' => []],
        ['sonarrSeriesId' => 2, 'sonarrEpisodeId' => 91, 'seriesTitle' => 'The Extraordinarily Long Example Series Title Of Doom', 'episode_number' => '1x01', 'title' => 'Pilot', 'episodeTitle' => 'Pilot', 'sceneName' => 'Example.Series.S01E01.1080p', 'monitored' => true, 'missing_subtitles' => [$english], 'subtitles' => []],
    ];
    $movies = [
        ['radarrId' => 10, 'title' => 'Dune', 'sceneName' => 'Dune.2021.2160p.UHD.BluRay', 'monitored' => true, 'missing_subtitles' => [$swedish], 'subtitles' => [$english + ['path' => '/movies/dune/Dune.2021.en.srt']]],
        ['radarrId' => 11, 'title' => 'An Extremely Long Example Movie Title That Wraps Badly', 'sceneName' => 'Example.Movie.2022.1080p', 'monitored' => true, 'missing_subtitles' => [$english, $swedish], 'subtitles' => []],
        ['radarrId' => 12, 'title' => 'Example Movie', 'sceneName' => 'Example.Movie.2023.1080p', 'monitored' => false, 'missing_subtitles' => [$english], 'subtitles' => []],
    ];
    $episodeHistory = [
        ['sonarrSeriesId' => 1, 'sonarrEpisodeId' => 81, 'seriesTitle' => 'Severance', 'episodeTitle' => $longFile, 'episode_number' => '1x01', 'language' => $english, 'provider' => 'opensubtitlescom', 'action' => 1, 'score' => '95.5%', 'timestamp' => '2026-01-04 09:00:00', 'parsed_timestamp' => '2026-01-04T09:00:00Z'],
        ['sonarrSeriesId' => 1, 'sonarrEpisodeId' => 82, 'seriesTitle' => 'Severance', 'episodeTitle' => 'Example Episode With A Fairly Long Name 2', 'episode_number' => '1x02', 'language' => $swedish, 'provider' => 'podnapisi', 'action' => 3, 'score' => '88.1%', 'timestamp' => '2026-01-04 08:00:00', 'parsed_timestamp' => '2026-01-04T08:00:00Z'],
        ['sonarrSeriesId' => 2, 'sonarrEpisodeId' => 91, 'seriesTitle' => 'The Extraordinarily Long Example Series Title Of Doom', 'episodeTitle' => 'Pilot', 'episode_number' => '1x01', 'language' => $english, 'provider' => 'embeddedsubtitles', 'action' => 2, 'score' => '', 'timestamp' => '2026-01-03 22:00:00', 'parsed_timestamp' => '2026-01-03T22:00:00Z'],
    ];
    $movieHistory = [
        ['radarrId' => 10, 'title' => 'Dune', 'language' => $english, 'provider' => 'opensubtitlescom', 'action' => 1, 'score' => '91.0%', 'timestamp' => '2026-01-04 07:00:00', 'parsed_timestamp' => '2026-01-04T07:00:00Z'],
        ['radarrId' => 11, 'title' => 'An Extremely Long Example Movie Title That Wraps Badly', 'language' => $swedish, 'provider' => 'podnapisi', 'action' => 1, 'score' => '84.0%', 'timestamp' => '2026-01-04 06:00:00', 'parsed_timestamp' => '2026-01-04T06:00:00Z'],
        ['radarrId' => 12, 'title' => 'Example Movie', 'language' => $english, 'provider' => 'subdl', 'action' => 3, 'score' => '79.0%', 'timestamp' => '2026-01-03 21:00:00', 'parsed_timestamp' => '2026-01-03T21:00:00Z'],
    ];
    // The drawer's provider search: one candidate with a long release name.
    $candidate = ['provider' => 'opensubtitlescom', 'subtitle' => 'example-provider-id', 'language' => 'swe', 'forced' => false, 'hearing_impaired' => true, 'score' => 98, 'release_info' => [Str::beforeLast($longFile, '.mkv')], 'uploader' => 'example-uploader-with-a-long-name'];
    $available = ['responses' => ['200' => ['description' => 'OK']]];
    $swaggerPaths = [];
    foreach ([
        '/episodes' => ['get'], '/movies' => ['get', 'patch'], '/series' => ['patch'],
        '/episodes/wanted' => ['get'], '/movies/wanted' => ['get'], '/episodes/history' => ['get'], '/movies/history' => ['get'],
        '/providers/episodes' => ['get', 'post'], '/providers/movies' => ['get', 'post'], '/providers' => ['get'],
        '/episodes/subtitles' => ['patch', 'post', 'delete'], '/movies/subtitles' => ['patch', 'post', 'delete'], '/subtitles' => ['patch'],
        '/system/tasks' => ['get', 'post'], '/system/languages/profiles' => ['get'], '/system/settings' => ['get', 'post'], '/system/notifications' => ['get', 'post', 'patch'],
    ] as $path => $methods) {
        $swaggerPaths[$path] = array_fill_keys($methods, $available);
    }

    return [
        'bazarr.local:6767/api/swagger.json' => Http::response(['swagger' => '2.0', 'basePath' => '/api', 'info' => ['title' => 'Bazarr', 'version' => '1.6.0'], 'paths' => $swaggerPaths]),
        'bazarr.local:6767/api/system/health' => Http::response(['data' => []]),
        'bazarr.local:6767/api/system/status' => Http::response(['data' => ['bazarr_version' => '1.5.1']]),
        'bazarr.local:6767/api/system/settings' => Http::response(['data' => ['scheduler' => ['enabled' => true, 'interval_hours' => 6], 'subtitle_tools' => ['automatic_subtitle_synchronization' => true, 'use_postprocessing' => false]]]),
        'bazarr.local:6767/api/system/languages/profiles' => Http::response([['profileId' => 1, 'name' => 'English and Swedish', 'items' => [['language' => 'eng'], ['language' => 'swe']]]]),
        'bazarr.local:6767/api/episodes/wanted*' => Http::response(['data' => $episodes, 'total' => 3]),
        'bazarr.local:6767/api/movies/wanted*' => Http::response(['data' => $movies, 'total' => 3]),
        'bazarr.local:6767/api/episodes/history*' => Http::response(['data' => $episodeHistory, 'total' => 3]),
        'bazarr.local:6767/api/movies/history*' => Http::response(['data' => $movieHistory, 'total' => 3]),
        'bazarr.local:6767/api/providers/episodes*' => Http::response(['data' => [$candidate]]),
        'bazarr.local:6767/api/providers/movies*' => Http::response(['data' => [$candidate]]),
        // The library reads episodes per series id (GET /api/episodes?seriesid=…).
        'bazarr.local:6767/api/episodes*' => Http::response(['data' => array_slice($episodes, 0, 2)]),
        'bazarr.local:6767/api/series*' => Http::response(['data' => [['sonarrSeriesId' => 1, 'title' => 'Severance', 'episodeFileCount' => 6], ['sonarrSeriesId' => 2, 'title' => 'The Extraordinarily Long Example Series Title Of Doom', 'episodeFileCount' => 1]], 'total' => 2]),
        'bazarr.local:6767/api/movies*' => Http::response(['data' => array_slice($movies, 0, 2), 'total' => 2]),
        // Subtitles > Admin's task, provider and notification panels.
        'bazarr.local:6767/api/system/tasks' => Http::response(['data' => [['taskid' => 'search_missing', 'name' => 'Search for missing subtitles', 'status' => 'idle'], ['taskid' => 'sync_episodes', 'name' => 'Sync episodes with Sonarr', 'status' => 'running']]]),
        // A throttled provider shows its "Throttled until" time; a notification has a long unbroken name.
        'bazarr.local:6767/api/providers' => Http::response(['data' => [['name' => 'OpenSubtitles', 'status' => 'healthy'], ['name' => 'Podnapisi', 'status' => 'throttled', 'throttled_until' => '2026-01-05T12:00:00+00:00']]]),
        'bazarr.local:6767/api/system/notifications' => Http::response(['data' => [['id' => 1, 'name' => 'example_apprise_notification_target_with_a_long_unbroken_name', 'enabled' => true], ['id' => 2, 'name' => 'Example Discord', 'enabled' => false]]]),
        'bazarr.local:6767/*' => Http::response([]),
        'graphql.anilist.co' => Http::response(['data' => ['Page' => ['pageInfo' => ['hasNextPage' => false], 'media' => mobileLayoutAnimeEntries()]]]),
    ];
}

/**
 * The Subtitles and Seasonal anime models (Task 1's capture fixtures): a
 * Bazarr connection mapped to the fakes' Sonarr and Radarr, two subtitle
 * cases, the AniList source with id maps, and two library series. The worst
 * case per card: the first anime entry is in the library (Open), the second
 * is unmonitored (Monitor and Open), the third is requestable and the fourth
 * has no id map (Find match).
 */
function mobileLayoutFakeSubtitlesAndAnime(): void
{
    $serviceConnection = ServiceConnection::query()->where('name', 'Sonarr')->sole();
    $radarr = ServiceConnection::query()->where('name', 'Radarr')->sole();
    $bazarr = ServiceConnection::factory()->bazarr()->create(['name' => 'Bazarr', 'url' => 'http://bazarr.local:6767', 'api_key' => 'k']);
    BazarrServiceLink::factory()->create(['bazarr_connection_id' => $bazarr->id, 'related_connection_id' => $serviceConnection->id, 'role' => BazarrServiceRole::Sonarr]);
    BazarrServiceLink::factory()->create(['bazarr_connection_id' => $bazarr->id, 'related_connection_id' => $radarr->id, 'role' => BazarrServiceRole::Radarr]);

    SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id, 'service_connection_id' => $serviceConnection->id, 'scope' => 'tv', 'status' => SubtitleCaseStatus::NeedsReview,
        'evidence' => ['display_name' => 'The Extraordinarily Long Example Series Title Of Doom S01E01', 'missing_languages' => ['eng', 'swe']],
        'failure_reason' => 'No release with matching subtitles was found after three searches.',
    ]);
    SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id, 'service_connection_id' => $serviceConnection->id, 'scope' => 'tv', 'status' => SubtitleCaseStatus::Resolved,
        'evidence' => ['display_name' => 'Severance S01E02', 'missing_languages' => ['swe']], 'resolved_at' => now(),
    ]);

    config()->set('mediamanager.anime.source', 'anilist');
    // The page queues the id-map sync on a fresh install.
    Queue::fake();
    foreach (array_slice(mobileLayoutAnimeEntries(), 0, 3) as $index => $entry) {
        AnimeIdMap::factory()->tv()->create(['anilist_id' => $entry['id'], 'mal_id' => $entry['idMal'], 'tmdb_tv_id' => 7001 + $index, 'tvdb_id' => 81001 + $index, 'tmdb_season' => 1, 'tvdb_season' => 1]);
    }

    mobileLayoutSeedAnimeLibrary($serviceConnection);
}

/**
 * The first two anime entries in $sonarr's library: series 7, which the fakes
 * serve (Open), and series 8, which has no live answer, so its stored
 * unmonitored flag stands (Monitor). The card reads the active Sonarr's row.
 */
function mobileLayoutSeedAnimeLibrary(ServiceConnection $sonarr): void
{
    foreach ([[7, 81001, true], [8, 81002, false]] as [$sonarrId, $tvdbId, $monitored]) {
        IndexedSeries::factory()->create([
            'service_connection_id' => $sonarr->id, 'sonarr_id' => $sonarrId, 'tvdb_id' => $tvdbId, 'title' => 'Example Indexed Series '.$sonarrId, 'sort_title' => 'example indexed series '.$sonarrId, 'title_slug' => 'example-indexed-series-'.$sonarrId,
            'year' => 2022, 'status' => 'continuing', 'monitored' => $monitored, 'network' => 'Example Network', 'arr_added_at' => '2026-01-01 00:00:00',
        ]);
    }
}

/**
 * The models the admin detail rows name by id (901 and up, clear of the ids
 * the factories hand out): a benchmark case, two finished runs on it, a
 * schedule, a chat template and an AI conversation, all owned by $admin; a
 * second Sonarr connection showing selected disk paths, a processed webhook
 * event on the fakes' Prowlarr connection (id 901) (with an AI decision and a
 * dispatched action), a replacement attempt that needs attention, one
 * subtitle rule and a server with one open incident and one check.
 * Two prices and three usage records fill the AI prices table and the AI
 * usage invocations table, so those rows measure their row controls, and
 * second-opinion verdicts fill AI usage's agreement panel at its widest:
 * two-digit counts, an unattributed verdict (the counts line's last part), a
 * long unbroken event key under a per-event rule in the breakdown, and a
 * false alarm and a miss with long titles and reasons.
 */
function mobileLayoutSeedDetailModels(User $admin): void
{
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini']);
    AiModelPrice::factory()->create(['provider' => 'anthropic', 'model' => 'example-model-with-a-very-long-unbroken-name-0123456789']);
    AiUsageRecord::factory()->count(3)->create(['prompt_text' => 'Find the example series and tell me about it.']);
    $eventKey = 'sonarr:ExampleUnbrokenEventKeyThatRunsPastAPhoneScreen0123456789';
    resolve(DecisionAgentSettings::class)->setSecondOpinionEventOverrides([$eventKey => ['mode' => SecondOpinionMode::Reject, 'engine' => null, 'jev_threshold' => null]]);
    $verdict = ['type' => 'delete_series', 'webhook_event_id' => null, 'approved_by' => $admin->id];
    ActionRequest::factory()->count(10)->secondOpinion(true, eventKey: $eventKey)->create([...$verdict, 'status' => ActionRequestStatus::Completed]);
    ActionRequest::factory()->secondOpinion(false, reason: 'The example series is still airing, so deleting it would lose the episodes that are left this season.', eventKey: $eventKey)
        ->create([...$verdict, 'status' => ActionRequestStatus::Completed, 'title' => 'Delete Example Series With A Fairly Long Title From Sonarr']);
    ActionRequest::factory()->secondOpinion(true, eventKey: $eventKey)
        ->create([...$verdict, 'status' => ActionRequestStatus::Rejected, 'title' => 'Delete Another_Example_Series_With_An_Unbroken_Title_0123456789']);
    ActionRequest::factory()->secondOpinion(true, eventKey: $eventKey)->create([...$verdict, 'status' => ActionRequestStatus::Completed, 'approved_by' => null]);
    BenchmarkCase::factory()->create(['id' => 901, 'name' => 'Example stuck download case']);
    BenchmarkRun::factory()->completed()->create(['id' => 901, 'name' => 'Example baseline run', 'case_ids' => [901]]);
    BenchmarkRun::factory()->completed()->create(['id' => 902, 'name' => 'Example candidate run', 'case_ids' => [901]]);
    BenchmarkSchedule::factory()->create(['id' => 901, 'name' => 'Example nightly schedule', 'created_by' => $admin->id]);
    ChatTemplate::factory()->create(['id' => 901, 'name' => 'Example stuck downloads check', 'user_id' => $admin->id]);
    DB::table('agent_conversations')->insert(['id' => '01900000-0000-7000-8000-000000000901', 'participant_type' => $admin->getMorphClass(), 'participant_id' => $admin->id, 'title' => 'Example conversation about a long series title', 'created_at' => now(), 'updated_at' => now()]);
    // A second Sonarr connection on the same fake host, for the edit row: it
    // shows selected disk paths with their metric toggles. Its id is lower
    // than the fakes' own Sonarr (the sequence is kept past 902), so in admin
    // tests it is the active Sonarr; it gets the same anime library, so the
    // Seasonal anime cards show Open and Monitor here too.
    $sonarr = ServiceConnection::factory()->sonarr()->create(['id' => 902, 'name' => 'Sonarr Anime', 'url' => 'http://sonarr.local:8989', 'api_key' => 'k', 'settings' => ['disk' => ['mode' => 'selected', 'paths' => ['/data/tv'], 'display' => ['/data/tv' => 'both']]]]);
    mobileLayoutSeedAnimeLibrary($sonarr);
    WebhookEvent::factory()->processed()->create(['id' => 901, 'service_connection_id' => 901]);
    // The event's decision can become a benchmark case (a text usage row on
    // its invocation) and it dispatched one action, so the page shows both.
    AgentDecision::factory()->completed(1)->create(['webhook_event_id' => 901, 'invocation_id' => 'inv-example-901', 'summary' => 'Proposed a replacement for the example episode.']);
    AiUsageRecord::factory()->create(['invocation_id' => 'inv-example-901', 'kind' => AiUsageKind::Text, 'prompt_text' => 'Decide.', 'is_benchmark' => false]);
    ActionRequest::factory()->completed()->create(['webhook_event_id' => 901, 'type' => 'replace_media_file', 'target_service' => 'sonarr']);
    // The factory's own action request would bring a webhook event on a
    // random connection; the attempt hangs off the seeded Sonarr instead.
    MediaReplacementAttempt::factory()->needsAttention()->create([
        'id' => 901,
        'action_request_id' => ActionRequest::factory()->create(['type' => 'replace_media_file', 'webhook_event_id' => null])->id,
        'service_connection_id' => $sonarr->id,
    ]);
    // One subtitle rule, so the media replacement settings show a rule row.
    resolve(MediaReplacementSettings::class)->setConfiguration(['guidance' => ['anime' => ['notes' => '', 'rules' => [[
        'name' => 'Example English release rule',
        'enabled' => true,
        'strength' => 'guarantee',
        'languages' => ['English'],
        'conditions' => [['field' => 'title', 'value' => 'EXAMPLE']],
        'explanation' => 'The EXAMPLE tag marks releases with English subtitles.',
    ]]], 'tv' => ['notes' => '', 'rules' => []], 'movie' => ['notes' => '', 'rules' => []]]]);
    $server = Server::factory()->create(['id' => 901, 'name' => 'example-host-01']);
    ServerIncident::factory()->open()->create(['server_id' => $server->id]);
    // A check fills the server's check list, so the row measures its row actions.
    ServerCheck::factory()->mountPresent()->create(['server_id' => $server->id]);
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
 * Text that runs past its box in $root (every match, default `main`): a long
 * unbroken file name that a frame's overflow clips, which the box-based
 * overflow check cannot see. Each non-empty text node's line boxes are
 * measured against the content box of its nearest ancestor that lays out a
 * box of its own (not `inline`, not `contents`), so text inside a `span` or a
 * link counts against its block, and a `touch-target` `::after` is never
 * taken for text. A box that scrolls, clips on purpose or ends in an ellipsis
 * is skipped.
 */
function mobileLayoutClippedTextScript(string $root = 'main'): string
{
    $selector = json_encode($root, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const found = [];
            const range = document.createRange();
            for (const root of document.querySelectorAll({$selector})) {
                const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
                while (walker.nextNode()) {
                    const node = walker.currentNode;
                    if (node.textContent.trim() === '') { continue; }
                    let box = node.parentElement;
                    while (box && box !== root && ['inline', 'contents'].includes(getComputedStyle(box).display)) { box = box.parentElement; }
                    const style = box ? getComputedStyle(box) : null;
                    if (!box || box.clientWidth === 0 || style.overflowX !== 'visible' || style.textOverflow === 'ellipsis') { continue; }
                    const outer = box.getBoundingClientRect();
                    const left = outer.left + box.clientLeft;
                    const right = left + box.clientWidth;
                    range.selectNodeContents(node);
                    const past = [...range.getClientRects()].filter((rect) => rect.width > 0 && (rect.right > right + 1 || rect.left < left - 1));
                    if (past.length > 0) {
                        found.push(`\${box.tagName.toLowerCase()} "\${node.textContent.trim().slice(0, 40)}" \${Math.round(Math.min(...past.map((rect) => rect.left)))}..\${Math.round(Math.max(...past.map((rect) => rect.right)))} > \${Math.round(left)}..\${Math.round(right)}`);
                    }
                }
            }
            return found.slice(0, 10).join(' | ');
        })()
    JS;
}

/**
 * Where a wrapped text in $root that contains $needle breaks inside a word, as
 * a ' , ' list of `before|after` snippets; 'missing' when no such text is
 * there and 'not wrapped' when it fits on one line. A break after a space or
 * a dash is a word break.
 */
function mobileLayoutMidWordBreaksScript(string $root, string $needle): string
{
    $selector = json_encode($root, JSON_THROW_ON_ERROR);
    $encodedNeedle = json_encode($needle, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const root = document.querySelector({$selector});
            if (!root) { return 'missing'; }
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            const nodes = [];
            while (walker.nextNode()) { if (walker.currentNode.textContent.includes({$encodedNeedle})) { nodes.push(walker.currentNode); } }
            if (nodes.length === 0) { return 'missing'; }
            const breaks = [];
            let wraps = 0;
            for (const node of nodes) {
                const text = node.textContent;
                const range = document.createRange();
                let previousTop = null;
                for (let index = 0; index < text.length; index++) {
                    if (/\s/.test(text[index])) { continue; }
                    range.setStart(node, index);
                    range.setEnd(node, index + 1);
                    const rect = range.getClientRects()[0];
                    if (!rect) { continue; }
                    if (previousTop !== null && rect.top > previousTop + 2) {
                        wraps++;
                        if (!/[\s\-—]/.test(text[index - 1])) { breaks.push(`\${text.slice(Math.max(0, index - 12), index)}|\${text.slice(index, index + 12)}`); }
                    }
                    previousTop = rect.top;
                }
            }
            return wraps === 0 ? 'not wrapped' : breaks.join(' , ');
        })()
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
        ->assertScript(phoneScrollSliverScript($root), '')
        ->assertScript(mobileLayoutClippedTextScript($root), '');

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
        'activity log' => ['activity-log', '[data-filter-strip]', '[data-activity-feed]'],
        'action queue' => ['actions.requests.index', '[data-action-refresh]', '[data-action-cards]'],
        'notifications' => ['notifications.index', null],
        'profile' => ['profile.edit', null],
        'preferences' => ['settings.preferences.edit', null],
        'notification settings' => ['settings.notifications.edit', '[data-notification-save]'],
        'appearance' => ['appearance.edit', null],
        // Both sections list two records with 3-digit totals, so both pagers show.
        'wanted' => ['media.wanted.index', '[data-wanted-monitored]', 'main:has([data-wanted-next="sonarr"]):has([data-wanted-next="radarr"])'],
        'add series' => ['media.series.create', '[data-series-create-back]'],
        // Its Back button has no hook.
        'add movie' => ['media.movies.create', null],
        // The widest state: lookup result cards, one with a poster and one without.
        'add series results' => ['media.series.create', '[data-series-create-back]', 'main [data-slot="card"]', ['q' => 'severance']],
        'add movie results' => ['media.movies.create', null, 'main [data-slot="card"]', ['q' => 'dune']],
        'prowlarr search' => ['prowlarr.search', null, '[data-prowlarr-results]', ['q' => 'severance']],
        // The failed upstream reads: both Wanted sections and the indexer search show their errors.
        'wanted (failed reads)' => ['media.wanted.index', '[data-wanted-monitored]', 'main:has([data-wanted-section="sonarr"] [data-slot="alert"]):has([data-wanted-section="radarr"] [data-slot="alert"])', ['tab' => 'cutoff']],
        'prowlarr search (failed)' => ['prowlarr.search', null, '[data-prowlarr-error]', ['q' => 'failing']],
        // The Subtitles pages (Bazarr faked, long unbroken file names) and Seasonal anime (AniList faked).
        'subtitles' => ['bazarr.overview', null],
        'missing subtitles' => ['bazarr.missing', '[data-test="missing-filters"]', '[data-test^="subtitle-item-"]'],
        'subtitle library' => ['bazarr.library', '[data-test="library-filters"]', '[data-test^="subtitle-item-"]'],
        'subtitle history' => ['bazarr.history', '[data-test="history-filters"]'],
        // The filters are admin-only: 'the subtitle escalations page with every action and a pager' measures them.
        'subtitle escalations' => ['bazarr.escalations', null, '[data-test^="subtitle-escalation-"]'],
        // Every card action: Open, Monitor (an unmonitored series), Request and Find match.
        'seasonal anime' => ['media.anime.index', null, 'main:has([data-anime-open]):has([data-anime-monitor]):has([data-anime-request])'],
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
        // Waits for the populated agreement panel's breakdown (a per-event rule row) too.
        'ai usage' => ['admin.ai-usage.index', null, 'main:has([data-classification-gates]):has([data-agreement-breakdown="event"] [data-breakdown-override])'],
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
        'ai conversation' => ['admin.ai-conversations.show', '[data-conversation-archive]', null, ['conversation' => '01900000-0000-7000-8000-000000000901']],
        'benchmark runs' => ['admin.benchmarks.runs.index', '[data-benchmark-run-new]'],
        'new benchmark run' => ['admin.benchmarks.runs.create', null],
        'benchmark run' => ['admin.benchmarks.runs.show', '[data-benchmark-delete]', null, ['benchmarkRun' => 901]],
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
        // An admin's search adds a Grab button to every release row.
        'prowlarr search (admin)' => ['prowlarr.search', null, '[data-prowlarr-results] [data-prowlarr-grab]', ['q' => 'severance']],
        'new connection' => ['admin.connections.create', null],
        // The deferred indexer row: the Prowlarr indexers card measures its row actions.
        'connection' => ['admin.connections.edit', null, '[data-prowlarr-indexer-test="3"]', ['serviceConnection' => 901]],
        // Waits for all three deferred sections: disk paths, root folders and tags.
        'sonarr connection' => ['admin.connections.edit', null, 'main:has([data-disk-metric="both"]):has([data-sonarr-library-types] select):has([data-subtitle-check-tags] input[type="checkbox"])', ['serviceConnection' => 902]],
        'webhook event' => ['admin.webhook-log.show', null, null, ['webhookEvent' => 901]],
        'replacement attempts' => ['admin.media-replacement.attempts.index', null],
        'replacement attempt' => ['admin.media-replacement.attempts.show', '[data-attempt-header]', null, ['mediaReplacementAttempt' => 901]],
        // Its header has only secondary actions: the phone keeps the ⋯ menu.
        'server' => ['admin.servers.show', '[data-page-header-more]', null, ['server' => 901]],
        // The toolbar stays hand-rolled (R4); the title waits for the scene.
        'whisparr scene' => ['media.whisparr.show', '[data-search-now]', '[data-whisparr-show-title]', ['id' => 11]],
        'subtitles admin' => ['bazarr.admin.index', '[data-test="save-bazarr-settings"]', '[data-test="bazarr-admin-form"]'],
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
        'welcome' => ['home', null],
        // Login.vue has two [data-test="login-button"] branches (with and without SSO), so it gets no primary hook.
        'login' => ['login', null],
        'register' => ['register', '[data-test="register-user-button"]'],
        'forgot password' => ['password.request', '[data-test="email-password-reset-link-button"]'],
        // Fortify renders the form for any token.
        'reset password' => ['password.reset', '[data-test="reset-password-button"]', null, ['token' => 'example-token']],
    ];
}

/**
 * Fortify's signed-in auth pages (auth layout, no `<main>`): measured on
 * `body` like the guest pages, for a member whose email is not verified yet.
 *
 * @return array<string, array{0: string, 1: ?string, 2?: ?string, 3?: array<string, mixed>}>
 */
function mobileLayoutSignedInAuthPages(): array
{
    return [
        'confirm password' => ['password.confirm', '[data-test="confirm-password-button"]'],
        'verify email' => ['verification.notice', null],
    ];
}

/**
 * Auth pages only their flow reaches, as [routeName]: set-password needs an
 * invited user without a password, the two-factor challenge a login by a
 * user with two-factor confirmed.
 *
 * @return array<string, array{0: string}>
 */
function mobileLayoutFlowAuthPages(): array
{
    return [
        'set password' => ['auth.set-password'],
        'two-factor challenge' => ['two-factor.login'],
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
        'dashboard' => ['dashboard', [], null],
        'notifications' => ['notifications.index', [], null],
        'profile' => ['profile.edit', [], null],
        'preferences' => ['settings.preferences.edit', [], null],
        'appearance' => ['appearance.edit', [], null],
        'notification settings' => ['settings.notifications.edit', [], null],
        'wanted' => ['media.wanted.index', [], 'main:has([data-wanted-next="sonarr"]):has([data-wanted-next="radarr"])'],
        'add series' => ['media.series.create', [], null],
        'add movie' => ['media.movies.create', [], null],
        'add series results' => ['media.series.create', ['q' => 'severance'], 'main [data-slot="card"]'],
        'add movie results' => ['media.movies.create', ['q' => 'dune'], 'main [data-slot="card"]'],
        // As an admin, so every release row shows its Grab button.
        'prowlarr search' => ['prowlarr.search', ['q' => 'severance'], '[data-prowlarr-results]'],
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
        'ai usage' => ['admin.ai-usage.index', [], 'main:has([data-classification-gates]):has([data-agreement-breakdown="event"] [data-breakdown-override])'],
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
        'new connection' => ['admin.connections.create', [], null],
        'connection' => ['admin.connections.edit', ['serviceConnection' => 901], '[data-prowlarr-indexer-test="3"]'],
        'sonarr connection' => ['admin.connections.edit', ['serviceConnection' => 902], 'main:has([data-disk-metric="both"]):has([data-sonarr-library-types] select):has([data-subtitle-check-tags] input[type="checkbox"])'],
        'webhook log' => ['admin.webhook-log.index', [], null],
        'webhook event' => ['admin.webhook-log.show', ['webhookEvent' => 901], null],
        'jobs' => ['admin.jobs.index', [], null],
        'media replacement' => ['admin.media-replacement.index', [], null],
        'replacement attempts' => ['admin.media-replacement.attempts.index', [], null],
        'replacement attempt' => ['admin.media-replacement.attempts.show', ['mediaReplacementAttempt' => 901], null],
        'operational statistics' => ['admin.statistics.index', [], null],
        'activity log' => ['activity-log', [], null],
        'statistics' => ['statistics.index', [], '[data-statistics-heatmap]'],
        'server' => ['admin.servers.show', ['server' => 901], null],
        'whisparr' => ['media.whisparr.index', [], null],
        'whisparr scene' => ['media.whisparr.show', ['id' => 11], '[data-whisparr-show-title]'],
        'subtitles' => ['bazarr.overview', [], null],
        'missing subtitles' => ['bazarr.missing', [], '[data-test^="subtitle-item-"]'],
        'subtitle library' => ['bazarr.library', [], '[data-test^="subtitle-item-"]'],
        'subtitle history' => ['bazarr.history', [], null],
        'subtitle escalations' => ['bazarr.escalations', [], '[data-test^="subtitle-escalation-"]'],
        'seasonal anime' => ['media.anime.index', [], 'main:has([data-anime-open]):has([data-anime-monitor]):has([data-anime-request])'],
        'subtitles admin' => ['bazarr.admin.index', [], '[data-test="bazarr-admin-form"]'],
    ];
}

/**
 * Named `web` GET routes that render no page of their own, each with the
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
        'auth.authentik' => 'a redirect to the Authentik sign-in',
        'auth.authentik.callback' => 'the Authentik OAuth callback, which redirects',
        'auth.invite.accept' => 'a signed invite link that redirects to set-password',
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
    return [];
}

/**
 * Named GET routes in the `web` group: behind `auth` or `auth:<guard>`
 * (Fortify registers its routes with `auth:web`), behind `guest` or
 * `guest:<guard>`, or public (home, set-password). Every one needs a dataset
 * row, an exclusion or a pending entry.
 *
 * @return list<string>
 */
function mobileLayoutPageRouteNames(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (Illuminate\Routing\Route $route): bool => in_array('GET', $route->methods(), true))
        ->filter(fn (Illuminate\Routing\Route $route): bool => in_array('web', $route->gatherMiddleware(), true))
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
    $classified = collect([...mobileLayoutMemberPages(), ...mobileLayoutAdminPages(), ...mobileLayoutGuestPages(), ...mobileLayoutSignedInAuthPages(), ...mobileLayoutFlowAuthPages()])
        ->map(fn (array $row): string => $row[0])
        ->merge(array_keys(mobileLayoutExcludedRouteNames()))
        ->merge(mobileLayoutPendingRouteNames());

    return collect(mobileLayoutPageRouteNames())->diff($classified)->values()->all();
}

dataset('mobile layout member pages', mobileLayoutMemberPages());
dataset('mobile layout admin pages', mobileLayoutAdminPages());
dataset('mobile layout guest pages', mobileLayoutGuestPages());
dataset('mobile layout signed-in auth pages', mobileLayoutSignedInAuthPages());
dataset('mobile layout flow auth pages', mobileLayoutFlowAuthPages());
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
test('a signed-in auth page fits a phone screen', function (string $routeName, ?string $primary, ?string $deferred = null, array $parameters = []): void {
    $this->actingAs(User::factory()->member()->unverified()->create());

    mobileLayoutAssertPage(visit(route($routeName, $parameters, absolute: false))->on()->mobile()->assertNoSmoke(), $primary, $deferred, 'body');
})->with('mobile layout signed-in auth pages');

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

/**
 * @param  array<string, int|string>  $parameters
 */
test('every control on a guest page is a 40 px target on a phone', function (string $routeName, ?string $primary, ?string $deferred = null, array $parameters = []): void {
    $webpage = visit(route($routeName, $parameters, absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    mobileLayoutSettle($webpage);

    $webpage->assertScript(phoneSmallTargetsScript('body'), '');
})->with('mobile layout guest pages');

test('an auth page reached through its flow fits a phone screen with 40 px targets', function (string $routeName): void {
    if ($routeName === 'auth.set-password') {
        // An invited user signed in before setting a password.
        $this->actingAs(User::factory()->member()->create(['password' => null, 'invite_accepted_at' => null]));
        $webpage = visit(route($routeName, absolute: false))->on()->mobile()->assertNoSmoke();
    } else {
        // Without Authentik or Emby the login page shows its email form directly.
        config()->set('services.authentik.client_id');
        $user = User::factory()->member()->create();
        $user->forceFill([
            'two_factor_secret' => encrypt('EXAMPLESECRETKEY'),
            'two_factor_recovery_codes' => encrypt(json_encode(['example-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $webpage = visit(route('login', absolute: false))->on()->mobile()->assertNoSmoke();
        $webpage->script(appHydratedScript());
        $webpage->fill('email', $user->email)
            ->fill('password', 'password')
            ->click('[data-test="login-button"]');
    }

    $webpage->assertPathIs(route($routeName, absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());

    mobileLayoutAssertPage($webpage, null, null, 'body');
    $webpage->assertScript(phoneSmallTargetsScript('body'), '');
})->with('mobile layout flow auth pages');

/**
 * The sign-in a real install shows: Authentik and Emby buttons, the open Emby
 * form and the opened email-login collapsible.
 */
test('the login page with single sign-on fits a phone screen with 40 px targets', function (): void {
    config()->set('services.authentik.client_id', 'example-client-id');
    ServiceConnection::factory()->emby()->create(['name' => 'Emby', 'url' => 'http://emby.local:8096', 'api_key' => 'k']);

    $webpage = visit(route('login', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    mobileLayoutAssertPage($webpage, '[data-login-authentik]', '[data-login-emby]', 'body');
    $webpage->assertScript(phoneSmallTargetsScript('body'), '');

    $webpage->click('[data-login-emby]')
        ->click('[data-login-local-toggle]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-login-emby-form]") && document.getAnimations().length === 0'));
    mobileLayoutAssertPage($webpage, '[data-test="login-button"]', '[data-login-emby-form]', 'body');
    $webpage->assertScript(phoneSmallTargetsScript('body'), '');
});

test('every control on a signed-in auth page is a 40 px target on a phone', function (string $routeName): void {
    $this->actingAs(User::factory()->member()->unverified()->create());

    $webpage = visit(route($routeName, absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    mobileLayoutSettle($webpage);

    $webpage->assertScript(phoneSmallTargetsScript('body'), '');
})->with('mobile layout signed-in auth pages');

/**
 * The add form a result card opens (quality profile, root folder, Add and
 * Cancel), with a long first root folder: its select shows that path.
 */
test('the add form of an add page fits a phone screen', function (string $routeName, string $query, int $resultId): void {
    // The `*` keys differ from the defaults' keys, so these match first.
    $rootFolders = [
        ['id' => 1, 'path' => '/mnt/user/data/media/example-library-with-a-long-folder-name/tv', 'freeSpace' => 1_200_000_000_000],
        ['id' => 2, 'path' => '/data/tv', 'freeSpace' => 100_000_000_000],
    ];
    mobileLayoutFakeServices([
        'sonarr.local:8989/api/v3/rootfolder*' => Http::response($rootFolders),
        'radarr.local:7878/api/v3/rootfolder*' => Http::response($rootFolders),
    ]);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route($routeName, ['q' => $query], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    mobileLayoutSettle($webpage, sprintf('[data-add-result="%d"]', $resultId));
    $webpage->click(sprintf('[data-add-result="%d"]', $resultId));

    mobileLayoutAssertPage($webpage, '[data-add-root-folder]', '[data-add-root-folder]');
    $webpage->assertSeeIn('[data-add-root-folder]', '/mnt/user/data/media')
        ->assertScript(phoneSmallTargetsScript(), '');
})->with([
    'add series' => ['media.series.create', 'severance', 371980],
    'add movie' => ['media.movies.create', 'dune', 438631],
]);

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

test('the open subtitle item drawer fits a phone screen', function (): void {
    mobileLayoutFakeServices();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('bazarr.missing', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    mobileLayoutSettle($webpage, '[data-test^="subtitle-item-"]');
    // The episode with the long unbroken file name and an external track.
    $webpage->click('[data-test="subtitle-item-episode-81"]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-slot=\"sheet-content\"]") && document.querySelector("[data-slot=\"sheet-content\"]").getAnimations().length === 0'));
    mobileLayoutSettle($webpage);

    $webpage->assertScript(phoneOverflowScript('[data-slot="sheet-content"]'), '')
        ->assertScript(phoneOverlapScript('[data-slot="sheet-content"]'), '')
        ->assertScript(mobileLayoutClippedTextScript('[data-slot="sheet-content"]'), '')
        ->assertScript(phoneSmallTargetsScript('[data-slot="sheet-content"]'), '');

    // The widest state: the provider search lists a candidate with a long release name.
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-test=\"subtitle-search\"]:not([disabled])")'));
    $webpage->click('[data-test="subtitle-search"]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-test=\"candidate-request-0\"]")'));

    $webpage->assertScript(phoneOverflowScript('[data-slot="sheet-content"]'), '')
        ->assertScript(phoneOverlapScript('[data-slot="sheet-content"]'), '')
        ->assertScript(phoneScrollSliverScript('[data-slot="sheet-content"]'), '')
        ->assertScript(phoneSmallTargetsScript('[data-slot="sheet-content"]'), '');
});

/**
 * Two Bazarr connections (the connection select shows) and Bazarr's movie
 * reads failing (each inventory page shows its partial-read warning), seen
 * by an admin, whose tab strip also has Admin.
 */
test('a Subtitles page with two Bazarr connections and a failed read fits a phone screen', function (string $routeName, ?string $deferred, bool $failedRead): void {
    // A key unlike the defaults' keys, so it matches first.
    mobileLayoutFakeServices(['*bazarr.local:6767/api/movies*' => Http::response(['message' => 'Example upstream failure'], 500)]);
    $serviceConnection = ServiceConnection::query()->where('name', 'Bazarr')->sole();
    ServiceConnection::factory()->bazarr()->create(['name' => 'Example Second Bazarr With A Long Connection Name', 'url' => 'http://bazarr.local:6767', 'api_key' => 'k']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route($routeName, ['connection' => $serviceConnection->id], absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    mobileLayoutAssertPage($webpage, '[data-test="bazarr-connection"]', $deferred);
    $webpage->assertScript('document.querySelector("main").textContent.includes("temporarily unavailable")', $failedRead)
        ->assertScript(phoneSmallTargetsScript(), '');
})->with([
    'subtitles' => ['bazarr.overview', null, true],
    'missing subtitles' => ['bazarr.missing', '[data-test^="subtitle-item-"]', true],
    'subtitle library' => ['bazarr.library', '[data-test^="subtitle-item-"]', true],
    'subtitle history' => ['bazarr.history', '[data-test="history-filters"]', true],
    'subtitles admin' => ['bazarr.admin.index', '[data-test="bazarr-admin-form"]', false],
]);

/**
 * The titles are prose ("series — episode"): on a phone they wrap at a space,
 * and only an unbroken run (a file name) may split mid-word.
 */
test('a long spaced Subtitles title wraps at a space on a phone', function (): void {
    mobileLayoutFakeServices();
    $this->actingAs(User::factory()->member()->create());
    $title = 'Example Episode With A Fairly Long Name 2';

    $webpage = visit(route('bazarr.missing', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    mobileLayoutSettle($webpage, '[data-test="subtitle-item-episode-82"]');
    $webpage->assertScript(mobileLayoutMidWordBreaksScript('main', $title), '');

    $webpage->click('[data-test="subtitle-item-episode-82"]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-slot=\"sheet-content\"]") && document.querySelector("[data-slot=\"sheet-content\"]").getAnimations().length === 0'));
    $webpage->assertScript(mobileLayoutMidWordBreaksScript('[data-slot="sheet-content"] [data-slot="sheet-header"]', $title), '');

    foreach (['bazarr.library' => '[data-test="subtitle-item-episode-82"]', 'bazarr.history' => '[data-test="history-filters"]'] as $routeName => $deferred) {
        $webpage->navigate(route($routeName, absolute: false))->assertNoSmoke();
        mobileLayoutSettle($webpage, $deferred);
        $webpage->assertScript(mobileLayoutMidWordBreaksScript('main', $title), '');
    }
});

/**
 * Subtitles admin with every panel filled: a long unbroken mapped connection
 * name, a throttled provider, a notification with a long unbroken name (the
 * fakes), then the open notification setup with its two readonly URIs. Every
 * native input is 40 px on touch.
 */
test('the Subtitles admin page with every panel filled fits a phone screen', function (): void {
    mobileLayoutFakeServices();
    ServiceConnection::query()->where('name', 'Radarr')->sole()->update(['name' => 'Example_Radarr_Connection_With_A_Very_Long_Unbroken_Name_0123456789']);
    $this->actingAs(User::factory()->admin()->create());
    $inputs = '[...document.querySelectorAll("main input:not([type=\"checkbox\"]):not([type=\"radio\"])")].filter((input) => input.getBoundingClientRect().height > 0 && input.getBoundingClientRect().height < 39.5).map((input) => `${input.getAttribute("data-test") ?? input.type} ${Math.round(input.getBoundingClientRect().height)}px`).join(" | ")';

    $webpage = visit(route('bazarr.admin.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    mobileLayoutAssertPage($webpage, '[data-test="save-bazarr-settings"]', 'main:has([data-test="bazarr-admin-form"]):has([data-test="show-bazarr-notification-hint"])');
    $webpage->assertScript('["Throttled until", "example_apprise_notification_target", "Example_Radarr_Connection"].every((text) => document.querySelector("main").textContent.includes(text))', true)
        ->assertScript(phoneSmallTargetsScript(), '')
        ->assertScript($inputs, '');

    $webpage->click('[data-test="show-bazarr-notification-hint"]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-test=\"bazarr-apprise-config-uri\"]")'));
    mobileLayoutAssertPage($webpage, '[data-test="bazarr-apprise-config-uri"]');
    $webpage->assertScript($inputs, '');
});

/**
 * The admin's escalations: the filters, an investigate button on every case
 * (AI and Bazarr automation on) and three pages of cases, so the pager shows.
 */
test('the subtitle escalations page with every action and a pager fits a phone screen', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    resolve(BazarrAutomationSettings::class)->setConfiguration(['enabled' => true]);
    mobileLayoutFakeServices();
    $serviceConnection = ServiceConnection::query()->where('name', 'Bazarr')->sole();
    SubtitleCase::factory()->count(60)->create([
        'bazarr_connection_id' => $serviceConnection->id, 'service_connection_id' => ServiceConnection::query()->where('name', 'Sonarr')->sole()->id, 'scope' => 'tv', 'status' => SubtitleCaseStatus::NeedsReview,
        'evidence' => ['display_name' => 'Severance S01E03', 'missing_languages' => ['swe']],
    ]);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('bazarr.escalations', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    mobileLayoutAssertPage($webpage, '[data-test="escalation-filters"]', 'main:has([data-test^="investigate-subtitle-case-"]):has(nav[aria-label="Escalation pages"])');
    $webpage->assertScript(phoneSmallTargetsScript(), '');
});

test('the seasonal anime page with Seerr unreachable fits a phone screen', function (): void {
    mobileLayoutFakeServices(['*seerr.local:5055/api/v1/user*' => Http::response(['message' => 'Example upstream failure'], 500)]);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.anime.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    mobileLayoutAssertPage($webpage, null, 'main:has([data-anime-open]):has([data-seerr-picker-unreachable])');
    $webpage->assertScript(phoneSmallTargetsScript(), '');
});

/**
 * Every card action as a member (Open, Monitor, an enabled Request and Find
 * match, the fourth entry having no id map), then the match dialog listing
 * the Seerr search's candidates.
 */
test('the seasonal anime page and its open match dialog fit a phone screen', function (): void {
    mobileLayoutFakeServices();
    $this->actingAs(User::factory()->member()->create(['email' => 'requester@example.com']));

    $webpage = visit(route('media.anime.index', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    mobileLayoutSettle($webpage, 'main:has([data-anime-open]):has([data-anime-monitor]):has([data-anime-request]:not([disabled]))');
    $webpage->assertScript(phoneSmallTargetsScript(), '');

    $webpage->click('Find match');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-anime-match-candidate]") && document.querySelector("[role=\"dialog\"]").getAnimations().length === 0'));
    mobileLayoutSettle($webpage);

    $webpage->assertScript(phoneOverflowScript('[role="dialog"]'), '')
        ->assertScript(phoneOverlapScript('[role="dialog"]'), '')
        ->assertScript(phoneScrollSliverScript('[role="dialog"]'), '')
        ->assertScript(phoneSmallTargetsScript('[role="dialog"]'), '')
        ->assertScript('(() => { const rect = document.querySelector("[role=\"dialog\"]").getBoundingClientRect(); return rect.left >= 0 && rect.right <= window.innerWidth && rect.top >= 0 && rect.bottom <= window.innerHeight; })()', true);
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

test('the clipped-text check names text past its box and passes a touch target', function (): void {
    $this->actingAs(User::factory()->member()->create());
    $webpage = visit(route('profile.edit', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    // Unbroken text inside a span is measured against its block, which a frame clips.
    $webpage->script('document.querySelector("main").insertAdjacentHTML("afterbegin", `<div data-clipped-fixture style="width:100px;overflow:hidden"><div><span>ExampleUnbrokenTextThatRunsPastItsBox</span></div></div>`)');

    expect($webpage->script(mobileLayoutClippedTextScript()))->toContain('div "ExampleUnbrokenTextThatRunsPastItsBox"');

    // A touch-target's 40 px ::after is wider than its 24 px button, but its text fits.
    $webpage->script('document.querySelector("[data-clipped-fixture]").outerHTML = `<div data-clipped-fixture><button class="touch-target" style="width:24px;height:24px;padding:0;font-size:10px">OK</button></div>`');
    $webpage->assertScript('(() => { const button = document.querySelector("[data-clipped-fixture] button"); return parseFloat(getComputedStyle(button, "::after").width) > button.clientWidth; })()', true)
        ->assertScript(mobileLayoutClippedTextScript(), '');

    // Text that ends in an ellipsis is clipped on purpose.
    $webpage->script('document.querySelector("[data-clipped-fixture]").outerHTML = `<div data-clipped-fixture style="width:100px"><div class="truncate">ExampleUnbrokenTextThatRunsPastItsBox</div></div>`');
    $webpage->assertScript(mobileLayoutClippedTextScript(), '');
});

test('the phone gate classifies every page route', function (): void {
    $listed = collect([...mobileLayoutMemberPages(), ...mobileLayoutAdminPages(), ...mobileLayoutGuestPages(), ...mobileLayoutSignedInAuthPages(), ...mobileLayoutFlowAuthPages()])->map(fn (array $row): string => $row[0])->unique();
    $excluded = collect(mobileLayoutExcludedRouteNames())->keys();
    $pending = collect(mobileLayoutPendingRouteNames());

    $pageRoutes = collect(mobileLayoutPageRouteNames());

    expect(mobileLayoutUnclassifiedRouteNames())->toBe([])
        ->and($listed->merge($excluded)->merge($pending)->diff($pageRoutes)->values()->all())->toBe([])
        ->and($pending->intersect($listed->merge($excluded))->values()->all())->toBe([]);
});

test('the route guard catches an unlisted page behind auth or an auth guard', function (): void {
    Route::middleware(['web', 'auth'])->get('example-auth-page', fn (): Response => Inertia::render('Welcome'))->name('example.auth-page');
    Route::middleware(['web', 'auth:web'])->get('example-guarded-page', fn (): Response => Inertia::render('Welcome'))->name('example.guarded-page');

    expect(mobileLayoutUnclassifiedRouteNames())->toBe(['example.auth-page', 'example.guarded-page']);
});

test('the route guard catches an unlisted guest or public page', function (): void {
    Route::middleware(['web', 'guest'])->get('example-guest-page', fn (): Response => Inertia::render('Welcome'))->name('example.guest-page');
    Route::middleware(['web', 'guest:web'])->get('example-guarded-guest-page', fn (): Response => Inertia::render('Welcome'))->name('example.guarded-guest-page');
    Route::middleware('web')->get('example-public-page', fn (): Response => Inertia::render('Welcome'))->name('example.public-page');

    expect(mobileLayoutUnclassifiedRouteNames())->toBe(['example.guest-page', 'example.guarded-guest-page', 'example.public-page']);
});
