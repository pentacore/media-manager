<?php

declare(strict_types=1);

use App\Enums\BazarrServiceRole;
use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Enums\SubtitleCaseStatus;
use App\Jobs\RunSubtitleAdvisor;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
use App\Models\SubtitleUpload;
use App\Models\User;
use App\Settings\BazarrAutomationSettings;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ParseMultipartBrowserRequests;

test('viewer can browse the subtitle center', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Primary Bazarr',
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);

    Http::fake([
        'bazarr.test/api/system/health' => Http::response(['data' => []]),
        'bazarr.test/api/episodes/wanted*' => Http::response(['data' => [], 'total' => 0]),
        'bazarr.test/api/movies/wanted*' => Http::response(['data' => [], 'total' => 0]),
    ]);

    $this->actingAs(User::factory()->create());

    visit(route('bazarr.overview', ['connection' => $bazarr->id], false))
        ->assertSee('Subtitle Center')
        ->assertSee('Missing subtitles')
        ->assertNoSmoke()
        ->click('@subtitle-tab-missing')
        ->assertPathIs('/subtitles/missing')
        ->assertSee('Nothing is currently missing')
        ->assertNoSmoke()
        ->click('@subtitle-tab-library')
        ->assertPathIs('/subtitles/library')
        ->assertSee('No subtitle inventory found')
        ->assertNoSmoke()
        ->click('@subtitle-tab-history')
        ->assertPathIs('/subtitles/history')
        ->assertSee('No subtitle history found')
        ->assertNoSmoke();
});

test('member retries a review case with Media Advisor after confirmation', function (): void {
    // The retry is only offered — and only accepted — while the Advisor could
    // actually run, so both gates are open here and the queued job is faked.
    config()->set('mediamanager.ai.enabled', true);
    resolve(BazarrAutomationSettings::class)->setConfiguration(['enabled' => true]);
    Queue::fake([RunSubtitleAdvisor::class]);
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Primary Bazarr',
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    $subtitleCase = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'status' => SubtitleCaseStatus::NeedsReview,
        'evidence' => [
            'display_name' => 'Frieren S01E01',
            'missing_languages' => ['eng'],
        ],
    ]);
    SubtitleCaseAttempt::factory()->create([
        'subtitle_case_id' => $subtitleCase->id,
        'type' => SubtitleCaseAttemptType::Advisor,
        'summary' => [
            'result' => 'needs_review',
            'summary' => 'No safe replacement matched the required English subtitles.',
        ],
        'outcome' => SubtitleCaseAttemptOutcome::NeedsReview,
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
    ]);

    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('bazarr.escalations', ['connection' => $bazarr->id], false))
        ->assertSee('Subtitle escalations')
        ->assertSee('Frieren S01E01')
        ->assertSee('Retry with Media Advisor')
        ->assertSee('No safe replacement matched the required English subtitles.')
        ->assertSee('Linked Action Request: none')
        ->assertScript(
            sprintf(
                'document.querySelector(\'[data-test="investigate-subtitle-case-%d"]\').disabled === false',
                $subtitleCase->id,
            ),
        );

    $webpage->script(
        'window.__advisorConfirmMessage = ""; window.confirm = (message) => { window.__advisorConfirmMessage = message; return true; };',
    );

    $webpage
        ->click('@investigate-subtitle-case-'.$subtitleCase->id)
        ->assertScript(
            'window.__advisorConfirmMessage.includes("already been investigated")',
        )
        ->assertSee('Media Advisor investigation queued.')
        ->assertSee('No safe replacement matched the required English subtitles.')
        ->assertNoSmoke();

    $subtitleCaseAttempt = SubtitleCaseAttempt::query()
        ->where('type', SubtitleCaseAttemptType::Reconciliation)
        ->sole();

    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::ReplacementEligible)
        ->and($subtitleCaseAttempt->type)->toBe(SubtitleCaseAttemptType::Reconciliation)
        ->and($subtitleCaseAttempt->summary['requested_by_user_id'])->toBeInt();
});

test('admin manages non-secret Bazarr settings', function (): void {
    config()->set('mediamanager.cache.store', 'array');
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Primary Bazarr',
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
        'webhook_token' => 'notification-secret',
    ]);

    Http::fake([
        'bazarr.test/api/system/settings' => Http::sequence()
            ->push(['data' => [
                'scheduler' => ['enabled' => true, 'interval_hours' => 6],
                'subtitle_tools' => [
                    'automatic_subtitle_synchronization' => true,
                    'use_postprocessing' => false,
                ],
            ]])
            ->push([], 204)
            ->push(['data' => [
                'scheduler' => ['enabled' => true, 'interval_hours' => 12],
                'subtitle_tools' => [
                    'automatic_subtitle_synchronization' => true,
                    'use_postprocessing' => false,
                ],
            ]])
            ->push(['data' => [
                'scheduler' => ['enabled' => true, 'interval_hours' => 12],
                'subtitle_tools' => [
                    'automatic_subtitle_synchronization' => true,
                    'use_postprocessing' => false,
                ],
            ]]),
        'bazarr.test/api/system/languages/profiles' => Http::response([[
            'profileId' => 1,
            'name' => 'English',
            'items' => [['language' => 'eng']],
        ]]),
        'bazarr.test/api/system/tasks' => Http::response(['data' => [[
            'taskid' => 'search_missing',
            'name' => 'Search missing',
            'status' => 'idle',
        ]]]),
        'bazarr.test/api/providers' => Http::response(['data' => [[
            'name' => 'OpenSubtitles',
            'status' => 'healthy',
        ]]]),
        'bazarr.test/api/system/notifications' => Http::response(['data' => []]),
        'bazarr.test/api/swagger.json' => Http::response([
            'swagger' => '2.0',
            'basePath' => '/api',
            'info' => ['title' => 'Bazarr', 'version' => '1.6.0'],
            'paths' => [
                '/system/settings' => [
                    'get' => ['responses' => ['200' => ['description' => 'OK']]],
                    'post' => ['responses' => ['204' => ['description' => 'OK']]],
                ],
            ],
        ]),
    ]);

    $this->actingAs(User::factory()->admin()->create());

    visit(route('bazarr.admin.index', ['connection' => $bazarr->id], false))
        ->assertSee('Open Bazarr to edit credentials')
        ->assertSee('English')
        ->assertSee('OpenSubtitles')
        ->click('@show-bazarr-notification-hint')
        ->assertSee('Apprise config URI')
        ->assertSee('Authenticated notification URL')
        ->assertSee('Existing notification providers are not changed.')
        // Bazarr validates the value as an Apprise target, so the operator must
        // be handed a json:// URI rather than the plain webhook URL.
        ->assertScript(
            'document.querySelector(\'[data-test="bazarr-apprise-config-uri"]\').value.startsWith("json")',
        )
        ->assertScript(
            'document.querySelector(\'[data-test="bazarr-apprise-config-uri"]\').value.includes("X-Webhook-Token=notification-secret")',
        )
        ->fill('@scheduler-interval', '12')
        ->click('@save-bazarr-settings')
        ->assertSee('Bazarr settings updated.')
        ->fill('@automation-reconciliation_interval_minutes', '30')
        ->click('@save-bazarr-automation')
        ->assertSee('Bazarr automation updated.')
        ->assertNoSmoke();

    expect(ActivityLog::query()->where('action', 'bazarr.settings.updated')->count())->toBe(1)
        ->and(ActivityLog::query()->where('action', 'bazarr.automation.updated')->count())->toBe(1);
});

test('member searches and requests an exact subtitle from the item drawer', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Primary Bazarr',
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    $radarr = ServiceConnection::factory()->radarr()->create();
    BazarrServiceLink::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $radarr->id,
        'role' => BazarrServiceRole::Radarr,
    ]);
    $movie = [
        'radarrId' => 801,
        'title' => 'Example Movie',
        'sceneName' => 'Example.Movie.2024.1080p',
        'path' => '/media/movies/Example Movie (2024)',
        'monitored' => true,
        'missing_subtitles' => [[
            'name' => 'Swedish',
            'code2' => 'sv',
            'code3' => 'swe',
            'forced' => false,
            'hi' => false,
        ]],
        'subtitles' => [],
    ];
    $candidate = [
        'provider' => 'AnimeTosho',
        'subtitle' => 'private-provider-id',
        'language' => 'swe',
        'forced' => false,
        'hearing_impaired' => false,
        'score' => 98,
        'release_info' => ['Example.Movie.2024.1080p'],
    ];

    fakeServiceHttp(function (Request $request) use ($movie, $candidate) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match ($path) {
            '/api/movies/wanted' => Http::response(['data' => [$movie], 'total' => 1]),
            '/api/movies' => Http::response(['data' => [$movie], 'total' => 1]),
            '/api/movies/history' => Http::response(['data' => [], 'total' => 0]),
            '/api/providers/movies' => Http::response(['data' => [$candidate]]),
            '/api/swagger.json' => Http::response([
                'swagger' => '2.0',
                'basePath' => '/api',
                'info' => ['title' => 'Bazarr', 'version' => '1.6.0'],
                'paths' => [
                    '/providers/episodes' => [
                        'get' => ['responses' => ['200' => ['description' => 'OK']]],
                        'post' => ['responses' => ['204' => ['description' => 'OK']]],
                    ],
                    '/providers/movies' => [
                        'get' => ['responses' => ['200' => ['description' => 'OK']]],
                        'post' => ['responses' => ['204' => ['description' => 'OK']]],
                    ],
                    '/episodes/subtitles' => [
                        'patch' => ['responses' => ['204' => ['description' => 'OK']]],
                    ],
                    '/movies/subtitles' => [
                        'patch' => ['responses' => ['204' => ['description' => 'OK']]],
                    ],
                ],
            ]),
            default => Http::response(['data' => []]),
        };
    });

    $this->actingAs(User::factory()->member()->create());

    visit(route('bazarr.missing', ['connection' => $bazarr->id], false))
        ->assertSee('Example Movie')
        ->click('@subtitle-item-movie-801')
        ->assertSee('Find subtitles')
        ->click('@subtitle-search')
        ->assertSee('AnimeTosho')
        ->click('@candidate-request-0')
        ->assertSee('Subtitle operation added to the Action Queue.')
        ->assertNoSmoke();

    expect(ActionRequest::query()->where('type', 'bazarr_download_exact')->count())->toBe(1);
});

/**
 * @param  list<array<string, mixed>>  $subtitles
 * @param  list<array{0: string, 1: string}>  $extraPaths
 * @return array<string, mixed>
 */
function fakeBazarrLibraryMovie(array $subtitles, array $extraPaths): array
{
    $movie = [
        'radarrId' => 801,
        'title' => 'Example Movie',
        'sceneName' => 'Example.Movie.2024.1080p',
        'path' => '/media/movies/Example Movie (2024)',
        'monitored' => true,
        'missing_subtitles' => [],
        'subtitles' => $subtitles,
    ];
    $paths = ['/movies' => ['get' => ['responses' => ['200' => ['description' => 'OK']]]]];

    foreach ($extraPaths as [$path, $method]) {
        $paths[$path][$method] = ['responses' => ['200' => ['description' => 'OK']]];
    }

    fakeServiceHttp(fn (Request $request) => match (parse_url($request->url(), PHP_URL_PATH)) {
        '/api/movies' => Http::response(['data' => [$movie], 'total' => 1]),
        '/api/providers/movies' => Http::response(['data' => []]),
        '/api/swagger.json' => Http::response([
            'swagger' => '2.0',
            'basePath' => '/api',
            'info' => ['title' => 'Bazarr', 'version' => '1.6.0'],
            'paths' => $paths,
        ]),
        default => Http::response(['data' => [], 'total' => 0]),
    });

    return $movie;
}

test('the missing list pages through wanted rows and filters by media type', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Primary Bazarr',
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    $radarr = ServiceConnection::factory()->radarr()->create();
    BazarrServiceLink::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $radarr->id,
        'role' => BazarrServiceRole::Radarr,
    ]);
    $movies = array_map(static fn (int $index): array => [
        'radarrId' => 800 + $index,
        'title' => 'Example Movie '.$index,
        'sceneName' => 'Example.Movie.'.$index,
        'monitored' => true,
        'missing_subtitles' => [[
            'name' => 'Swedish',
            'code2' => 'sv',
            'code3' => 'swe',
            'forced' => false,
            'hi' => false,
        ]],
        'subtitles' => [],
    ], range(1, 30));

    fakeServiceHttp(function (Request $request) use ($movies) {
        if (parse_url($request->url(), PHP_URL_PATH) !== '/api/movies/wanted') {
            return Http::response(['data' => [], 'total' => 0]);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response([
            'data' => array_slice($movies, (int) ($query['start'] ?? 0), (int) ($query['length'] ?? 25)),
            'total' => count($movies),
        ]);
    });

    $this->actingAs(User::factory()->member()->create());

    // Thirty wanted rows over a 25-row page: without navigation the last five
    // were unreachable from the Subtitle Center.
    visit(route('bazarr.missing', ['connection' => $bazarr->id], false))
        ->assertSee('Example Movie 1')
        ->assertDontSee('Example Movie 30')
        ->assertSee('Showing 1–25 of 30')
        ->click('@inventory-pager-next')
        ->assertSee('Example Movie 30')
        ->assertDontSee('Example Movie 1')
        ->assertSee('Showing 26–30 of 30')
        ->assertNoSmoke();

});

/**
 * A separate test rather than a second visit() above: a fresh navigation after
 * the pager interaction races the visit it replaces, so the assertions can land
 * on the page the browser has not left yet.
 */
test('the missing list applies a media type filter from the address', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Primary Bazarr',
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    $radarr = ServiceConnection::factory()->radarr()->create();
    BazarrServiceLink::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $radarr->id,
        'role' => BazarrServiceRole::Radarr,
    ]);

    fakeServiceHttp(fn (Request $request) => Http::response(
        parse_url($request->url(), PHP_URL_PATH) === '/api/movies/wanted'
            ? ['data' => [[
                'radarrId' => 801,
                'title' => 'Example Movie 1',
                'sceneName' => 'Example.Movie.1',
                'monitored' => true,
                'missing_subtitles' => [[
                    'name' => 'Swedish',
                    'code2' => 'sv',
                    'code3' => 'swe',
                    'forced' => false,
                    'hi' => false,
                ]],
                'subtitles' => [],
            ]], 'total' => 1]
            : ['data' => [], 'total' => 0],
    ));

    $this->actingAs(User::factory()->member()->create());

    // Only movies are mapped, so filtering to episodes empties the list.
    // Asserting the empty state first also waits for hydration, which is what
    // puts the active filter on the select.
    visit(route('bazarr.missing', ['connection' => $bazarr->id, 'media_type' => 'episode'], false))
        ->assertSee('Nothing is currently missing')
        ->assertDontSee('Example Movie 1')
        ->assertScript('document.querySelector(\'[data-test="missing-media-type-filter"]\').value === "episode"')
        ->assertNoSmoke();
});

test('member syncs an existing subtitle track from the item drawer', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Primary Bazarr',
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    $radarr = ServiceConnection::factory()->radarr()->create();
    BazarrServiceLink::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $radarr->id,
        'role' => BazarrServiceRole::Radarr,
    ]);
    fakeBazarrLibraryMovie(
        [[
            'code3' => 'swe',
            'path' => '/media/movies/Example Movie (2024)/Example.Movie.2024.swe.srt',
            'forced' => false,
            'hi' => false,
        ]],
        [['/subtitles', 'patch'], ['/movies/subtitles', 'delete'], ['/providers/movies', 'get']],
    );

    $this->actingAs(User::factory()->member()->create());

    visit(route('bazarr.library', ['connection' => $bazarr->id], false))
        ->assertSee('Example Movie')
        ->click('@subtitle-item-movie-801')
        ->assertSee('Current tracks')
        ->assertSee('Example.Movie.2024.swe.srt')
        // Wait for the operation controls themselves: the drawer renders its
        // track list before the buttons settle, and clicking too early times out
        // on a loaded machine.
        ->assertSee('Remove HI tags')
        ->click('@subtitle-track-0-sync')
        ->click('@confirm-subtitle-operation')
        ->assertSee('Subtitle operation added to the Action Queue.')
        ->assertNoSmoke();

    expect(ActionRequest::query()->where('type', 'bazarr_sync_subtitle')->count())->toBe(1);
});

test('track operations Bazarr cannot perform are disabled once capabilities load', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Primary Bazarr',
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    $radarr = ServiceConnection::factory()->radarr()->create();
    BazarrServiceLink::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $radarr->id,
        'role' => BazarrServiceRole::Radarr,
    ]);
    // This Bazarr exposes manual search but neither the subtitle patch endpoint
    // that backs sync and translate nor the delete endpoint.
    fakeBazarrLibraryMovie(
        [[
            'code3' => 'swe',
            'path' => '/media/movies/Example Movie (2024)/Example.Movie.2024.swe.srt',
            'forced' => false,
            'hi' => false,
        ]],
        [['/providers/movies', 'get']],
    );

    $this->actingAs(User::factory()->member()->create());

    visit(route('bazarr.library', ['connection' => $bazarr->id], false))
        ->click('@subtitle-item-movie-801')
        ->assertSee('Current tracks')
        // No manual search is needed: capabilities are discovered when the drawer
        // opens, so an unsupported operation is never offered in the first place.
        ->assertScript('document.querySelector(\'[data-test="subtitle-track-0-sync"]\').disabled === true')
        ->assertScript('document.querySelector(\'[data-test="subtitle-track-0-translate"]\').disabled === true')
        ->assertScript('document.querySelector(\'[data-test="subtitle-track-0-remove-hi"]\').disabled === true')
        ->assertScript('document.querySelector(\'[data-test="subtitle-track-0-delete"]\').disabled === true')
        ->assertNoSmoke();

    expect(ActionRequest::query()->count())->toBe(0);
});

test('operations a Bazarr without discovery cannot perform stay disabled', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Primary Bazarr',
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    $radarr = ServiceConnection::factory()->radarr()->create();
    BazarrServiceLink::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $radarr->id,
        'role' => BazarrServiceRole::Radarr,
    ]);
    // Swagger is unreadable, so no capability is ever known to be true. Every
    // operation must stay disabled rather than defaulting to enabled.
    fakeServiceHttp(fn (Request $request) => match (parse_url($request->url(), PHP_URL_PATH)) {
        '/api/movies' => Http::response(['data' => [[
            'radarrId' => 801,
            'title' => 'Example Movie',
            'sceneName' => 'Example.Movie.2024.1080p',
            'path' => '/media/movies/Example Movie (2024)',
            'monitored' => true,
            'missing_subtitles' => [],
            'subtitles' => [],
        ]], 'total' => 1]),
        '/api/swagger.json' => Http::response(['message' => 'not found'], 404),
        default => Http::response(['data' => [], 'total' => 0]),
    });

    $this->actingAs(User::factory()->member()->create());

    visit(route('bazarr.library', ['connection' => $bazarr->id], false))
        ->click('@subtitle-item-movie-801')
        ->assertSee('Find subtitles')
        ->assertScript('document.querySelector(\'[data-test="subtitle-search"]\').disabled === true')
        ->assertScript('document.querySelector(\'[data-test="subtitle-request-best"]\').disabled === true')
        ->assertScript('document.querySelector(\'[data-test="subtitle-upload-open"]\').disabled === true')
        ->assertNoSmoke();

    expect(ActionRequest::query()->count())->toBe(0);
});

test('admin filters escalations by status and by Bazarr connection', function (): void {
    $first = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Primary Bazarr',
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    $second = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Secondary Bazarr',
        'url' => 'http://bazarr-two.test',
        'api_key' => 'bazarr-two-secret',
    ]);
    SubtitleCase::factory()->create([
        'bazarr_connection_id' => $first->id,
        'status' => SubtitleCaseStatus::NeedsReview,
        'evidence' => ['display_name' => 'Needs Review Case', 'missing_languages' => ['eng']],
    ]);
    SubtitleCase::factory()->create([
        'bazarr_connection_id' => $first->id,
        'status' => SubtitleCaseStatus::Resolved,
        'evidence' => ['display_name' => 'Resolved Case', 'missing_languages' => ['eng']],
    ]);
    SubtitleCase::factory()->create([
        'bazarr_connection_id' => $second->id,
        'status' => SubtitleCaseStatus::NeedsReview,
        'evidence' => ['display_name' => 'Second Connection Case', 'missing_languages' => ['eng']],
    ]);

    fakeServiceHttp(fn (): mixed => Http::response(['data' => [], 'total' => 0]));

    $this->actingAs(User::factory()->admin()->create());

    visit(route('bazarr.escalations', ['connection' => $first->id], false))
        ->assertSee('Needs Review Case')
        ->assertSee('Resolved Case')
        ->assertDontSee('Second Connection Case')
        ->assertNoSmoke();
});

/**
 * Loaded by address rather than by driving the select, and kept in its own test:
 * `cases` polls every two seconds against the address bar, so both a control
 * interaction and a second navigation inside one test race the in-flight poll.
 * Asserting the select mirrors the active status still proves the control is
 * bound to the query it navigates to.
 */
test('the escalation status filter narrows the list and reflects the active status', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Primary Bazarr',
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'status' => SubtitleCaseStatus::NeedsReview,
        'evidence' => ['display_name' => 'Needs Review Case', 'missing_languages' => ['eng']],
    ]);
    SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'status' => SubtitleCaseStatus::Resolved,
        'evidence' => ['display_name' => 'Resolved Case', 'missing_languages' => ['eng']],
    ]);

    fakeServiceHttp(fn (): mixed => Http::response(['data' => [], 'total' => 0]));

    $this->actingAs(User::factory()->admin()->create());

    visit(route('bazarr.escalations', [
        'connection' => $bazarr->id,
        'status' => SubtitleCaseStatus::NeedsReview->value,
    ], false))
        ->assertSee('Needs Review Case')
        ->assertDontSee('Resolved Case')
        ->assertScript(
            'document.querySelector(\'[data-test="escalation-status-filter"]\').value === "'
            .SubtitleCaseStatus::NeedsReview->value.'"',
        )
        ->assertNoSmoke();
});

/**
 * The connection dimension gets its own test rather than a second visit() in the
 * one above: the page polls `cases` every two seconds, so a filter interaction
 * followed by a fresh navigation races the in-flight poll and either
 * connection's rows can win.
 */
test('escalations stay scoped to the selected Bazarr connection', function (): void {
    $first = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Primary Bazarr',
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    $second = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Secondary Bazarr',
        'url' => 'http://bazarr-two.test',
        'api_key' => 'bazarr-two-secret',
    ]);
    SubtitleCase::factory()->create([
        'bazarr_connection_id' => $first->id,
        'status' => SubtitleCaseStatus::NeedsReview,
        'evidence' => ['display_name' => 'First Connection Case', 'missing_languages' => ['eng']],
    ]);
    SubtitleCase::factory()->create([
        'bazarr_connection_id' => $second->id,
        'status' => SubtitleCaseStatus::NeedsReview,
        'evidence' => ['display_name' => 'Second Connection Case', 'missing_languages' => ['eng']],
    ]);

    fakeServiceHttp(fn (): mixed => Http::response(['data' => [], 'total' => 0]));

    $this->actingAs(User::factory()->admin()->create());

    visit(route('bazarr.escalations', ['connection' => $second->id], false))
        ->assertSee('Secondary Bazarr')
        ->assertSee('Second Connection Case')
        ->assertDontSee('First Connection Case')
        ->assertNoSmoke();
});

test('member uploads a subtitle from the item drawer', function (): void {
    resolve(Kernel::class)->pushMiddleware(ParseMultipartBrowserRequests::class);
    $this->seed(ActionTypeConfigSeeder::class);
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Primary Bazarr',
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    // A routable host, because the upload case is keyed by the live Radarr file
    // identity and loopback URLs are deliberately left unfaked.
    $radarr = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.test',
        'api_key' => 'radarr-secret',
    ]);
    BazarrServiceLink::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $radarr->id,
        'role' => BazarrServiceRole::Radarr,
    ]);
    $movie = [
        'radarrId' => 802,
        'title' => 'Upload Example Movie',
        'sceneName' => 'Upload.Example.Movie.2024.1080p',
        'path' => '/media/movies/Upload Example Movie (2024)',
        'monitored' => true,
        'missing_subtitles' => [[
            'name' => 'English',
            'code2' => 'en',
            'code3' => 'eng',
            'forced' => false,
            'hi' => false,
        ]],
        'subtitles' => [],
    ];

    fakeServiceHttp(function (Request $request) use ($movie) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match ($path) {
            '/api/movies/wanted', '/api/movies' => Http::response(['data' => [$movie], 'total' => 1]),
            '/api/movies/history' => Http::response(['data' => [], 'total' => 0]),
            // The linked upload case is keyed by the live Radarr file identity.
            '/api/v3/movie/802' => Http::response(['id' => 802, 'title' => 'Upload Example Movie', 'movieFileId' => 92]),
            '/api/v3/moviefile/92' => Http::response([
                'id' => 92,
                'size' => 7_516_192_768,
                'dateAdded' => '2026-07-21T09:00:00Z',
                'sceneName' => 'Upload.Example.Movie.2024.1080p',
                'path' => '/media/movies/Upload Example Movie (2024)/movie.mkv',
            ]),
            '/api/swagger.json' => Http::response([
                'swagger' => '2.0',
                'basePath' => '/api',
                'info' => ['title' => 'Bazarr', 'version' => '1.6.0'],
                'paths' => [
                    // Upload capability requires the endpoint on both media types.
                    '/movies/subtitles' => [
                        'post' => ['responses' => ['204' => ['description' => 'OK']]],
                    ],
                    '/episodes/subtitles' => [
                        'post' => ['responses' => ['204' => ['description' => 'OK']]],
                    ],
                ],
            ]),
            default => Http::response(['data' => []]),
        };
    });

    $this->actingAs(User::factory()->member()->create());
    $subtitleContents = base64_encode((string) file_get_contents(
        base_path('tests/Fixtures/Subtitles/valid-en.srt'),
    ));

    $webpage = visit(route('bazarr.missing', ['connection' => $bazarr->id], false))
        ->assertSee('Upload Example Movie')
        ->click('@subtitle-item-movie-802');

    // Upload stays disabled until capability discovery confirms Bazarr supports it.
    $webpage->assertScript(
        'document.querySelector(\'[data-test="subtitle-upload-open"]\').disabled === false',
    );
    $webpage->script("document.querySelector('[data-test=\"subtitle-upload-open\"]').click()");
    $webpage->assertSee('Upload subtitle file');

    $webpage->script(<<<JS
        const bytes = Uint8Array.from(atob('{$subtitleContents}'), character => character.charCodeAt(0));
        const file = new File([bytes], 'valid-en.srt', { type: 'application/x-subrip' });
        const transfer = new DataTransfer();
        transfer.items.add(file);
        const input = document.querySelector('[name="subtitle_file"]');
        input.files = transfer.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        JS);

    $webpage
        ->click('@confirm-subtitle-operation')
        ->assertSee('Subtitle upload added to the Action Queue.')
        ->assertNoSmoke();

    expect(ActionRequest::query()->where('type', 'bazarr_upload_subtitle')->count())->toBe(1)
        ->and(SubtitleUpload::query()->count())->toBe(1);
});
