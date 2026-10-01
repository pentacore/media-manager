<?php

declare(strict_types=1);

use App\Enums\WhisparrVersion;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\User;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * @return list<array{coverType: string, remoteUrl: string}>
 */
function whisparrBrowserPoster(): array
{
    return [['coverType' => 'poster', 'remoteUrl' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7']];
}

/**
 * @return list<array<string, mixed>>
 */
function whisparrBrowserMovies(): array
{
    return [
        ['id' => 11, 'title' => 'Aurora Scene', 'year' => 2024, 'monitored' => true, 'hasFile' => true, 'sizeOnDisk' => 3_000_000_000, 'qualityProfileId' => 1, 'images' => whisparrBrowserPoster(), 'path' => '/data/whisparr/Aurora Scene', 'overview' => 'First.'],
        ['id' => 12, 'title' => 'Borealis Scene', 'year' => 2023, 'monitored' => true, 'hasFile' => false, 'sizeOnDisk' => 0, 'qualityProfileId' => 1, 'images' => whisparrBrowserPoster()],
        ['id' => 13, 'title' => 'Cirrus Scene', 'year' => 2022, 'monitored' => false, 'hasFile' => false, 'sizeOnDisk' => 0, 'qualityProfileId' => 2, 'images' => whisparrBrowserPoster()],
        // Sparse on purpose: no images, no year — renders with every key present.
        ['id' => 14, 'title' => 'Drift Scene', 'monitored' => true],
    ];
}

function fakeWhisparrBrowserLibrary(): void
{
    $movies = whisparrBrowserMovies();

    Http::fake([
        'whisparr.local:6969/api/v3/movie/11*' => Http::response($movies[0]),
        'whisparr.local:6969/api/v3/movie/12*' => Http::response($movies[1]),
        'whisparr.local:6969/api/v3/movie/13*' => Http::response($movies[2]),
        'whisparr.local:6969/api/v3/movie' => Http::response($movies),
        'whisparr.local:6969/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'Any'], ['id' => 2, 'name' => 'HD']]),
        'whisparr.local:6969/api/v3/command' => Http::response(['id' => 900], 201),
    ]);
}

/**
 * Dispatches a touch pointerdown followed by a native click on the given
 * poster, waiting (inside the page) for the condition to settle before
 * evaluate() returns — Inertia's SPA navigation and Vue's reactive
 * `data-blurred` update both happen asynchronously after the click.
 */
function whisparrBrowserTapScript(string $posterSelector, string $waitUntilSelector): string
{
    $posterSelector = addslashes($posterSelector);
    $waitUntilSelector = addslashes($waitUntilSelector);

    return <<<JS
        (async () => {
            const poster = document.querySelector('{$posterSelector}');
            poster.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerType: 'touch' }));
            poster.click();

            for (let attempt = 0; attempt < 50; attempt++) {
                if (document.querySelector('{$waitUntilSelector}')) {
                    break;
                }
                await new Promise((resolve) => setTimeout(resolve, 20));
            }
        })()
    JS;
}

beforeEach(function (): void {
    ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V3)->create(['url' => 'http://whisparr.local:6969', 'api_key' => 'k']);
});

test('an admin browses the Whisparr library with every image poster blurred until hover', function (): void {
    fakeWhisparrBrowserLibrary();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.whisparr.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-whisparr-card="11"] [data-whisparr-title]', 'Aurora Scene')
        ->assertSeeIn('[data-whisparr-card="14"] [data-whisparr-title]', 'Drift Scene')
        ->assertCount('[data-whisparr-card]', 4)
        ->assertCount('[data-poster][data-blurred="true"]', 3)
        ->hover('[data-whisparr-card="11"] [data-poster]')
        ->assertPresent('[data-whisparr-card="11"] [data-poster][data-blurred="false"]')
        ->assertCount('[data-poster][data-blurred="true"]', 2)
        ->assertSeeIn('[data-sidebar="content"]', 'Whisparr')
        ->assertNoSmoke();
});

test('the missing filter keeps only monitored titles without a file', function (): void {
    fakeWhisparrBrowserLibrary();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.whisparr.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-whisparr-card="11"] [data-whisparr-title]', 'Aurora Scene')
        ->click('[data-whisparr-filter="missing"]')
        ->assertCount('[data-whisparr-card]', 2)
        ->assertPresent('[data-whisparr-card="12"]')
        ->assertPresent('[data-whisparr-card="14"]');
});

test('an admin who turned the blur off sees sharp posters', function (): void {
    fakeWhisparrBrowserLibrary();
    $this->actingAs(User::factory()->admin()->create(['preferences' => ['whisparr_blur_posters' => false]]));

    visit(route('media.whisparr.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-whisparr-card="11"] [data-whisparr-title]', 'Aurora Scene')
        ->assertCount('[data-poster][data-blurred="true"]', 0);
});

test('a blurred poster reveals on keyboard focus and re-blurs on blur', function (): void {
    fakeWhisparrBrowserLibrary();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.whisparr.index', absolute: false))
        ->assertNoSmoke()
        ->assertPresent('[data-whisparr-card="11"] [data-poster][data-blurred="true"]');

    $webpage->script("document.querySelector('[data-whisparr-card=\"11\"] [data-poster]').focus()");
    $webpage->assertPresent('[data-whisparr-card="11"] [data-poster][data-blurred="false"]');

    $webpage->script("document.querySelector('[data-whisparr-card=\"11\"] [data-poster]').blur()");
    $webpage->assertPresent('[data-whisparr-card="11"] [data-poster][data-blurred="true"]');
});

test('a first tap on a blurred poster reveals it without navigating, a second tap navigates', function (): void {
    fakeWhisparrBrowserLibrary();
    $this->actingAs(User::factory()->admin()->create());

    $posterSelector = '[data-whisparr-card="11"] [data-poster]';

    $webpage = visit(route('media.whisparr.index', absolute: false))
        ->assertNoSmoke()
        ->assertPresent("{$posterSelector}[data-blurred=\"true\"]");

    $webpage->script(whisparrBrowserTapScript($posterSelector, "{$posterSelector}[data-blurred=\"false\"]"));
    $webpage->assertPresent("{$posterSelector}[data-blurred=\"false\"]")
        ->assertRoute('media.whisparr.index');

    $webpage->script(whisparrBrowserTapScript($posterSelector, '[data-whisparr-show-title]'));
    $webpage->assertRoute('media.whisparr.show', ['id' => 11])
        ->assertSeeIn('[data-whisparr-show-title]', 'Aurora Scene');
});

test('the show page keeps the poster blurred and shows the path', function (): void {
    fakeWhisparrBrowserLibrary();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.whisparr.show', ['id' => 11], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-whisparr-show-title]', 'Aurora Scene')
        ->assertSeeIn('[data-whisparr-path]', '/data/whisparr/Aurora Scene')
        ->assertCount('[data-poster][data-blurred="true"]', 1);
});

test('a Whisparr refusal shows an error instead of an empty library', function (): void {
    Http::fake([
        'whisparr.local:6969/api/v3/movie' => Http::response(['message' => 'Unauthorized'], 401),
        'whisparr.local:6969/api/v3/qualityprofile' => Http::response([]),
    ]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.whisparr.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-whisparr-error]', 'Whisparr refused the request — check the connection settings.')
        ->assertCount('[data-whisparr-card]', 0);
});

test('a v2 site lists its scenes by year', function (): void {
    ServiceConnection::query()->update(['is_active' => false]);
    ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V2)->create(['url' => 'http://whisparr2.local:6969', 'api_key' => 'k']);
    Http::fake([
        'whisparr2.local:6969/api/v3/series/5*' => Http::response(['id' => 5, 'title' => 'Site Five', 'year' => 2019, 'monitored' => true, 'qualityProfileId' => 1, 'statistics' => ['episodeFileCount' => 1, 'sizeOnDisk' => 1_000_000_000], 'images' => whisparrBrowserPoster(), 'path' => '/data/sites/Site Five']),
        'whisparr2.local:6969/api/v3/episode*' => Http::response([['id' => 51, 'seasonNumber' => 2024, 'title' => 'Scene 2024-A', 'airDate' => '2024-02-01', 'hasFile' => true, 'monitored' => true]]),
        'whisparr2.local:6969/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'Any']]),
    ]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.whisparr.show', ['id' => 5], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-whisparr-show-title]', 'Site Five')
        ->assertSeeIn('[data-whisparr-scene-year="2024"]', 'Scene 2024-A');
});

test('an admin monitors, searches and deletes from the Whisparr title page', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);
    Queue::fake([ExecuteActionRequest::class]);
    fakeWhisparrBrowserLibrary();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.whisparr.show', ['id' => 11], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-whisparr-show-title]', 'Aurora Scene')
        ->click('[data-whisparr-actions] [data-monitor-toggle]')
        ->assertSee('Monitoring updated.')
        ->click('[data-whisparr-actions] [data-search-now]')
        ->assertSee('Search started.')
        ->click('[data-delete-trigger]')
        ->click('[data-delete-files]')
        ->click('[data-delete-confirm]')
        ->assertSee('Queued for approval in the Action Queue.')
        ->assertNoSmoke();

    expect(ActionRequest::query()->where('type', 'whisparr_monitor_item')->sole()->payload['monitored'])->toBeFalse()
        ->and(ActionRequest::query()->where('type', 'whisparr_search')->exists())->toBeTrue()
        ->and(ActionRequest::query()->where('type', 'whisparr_delete_item')->sole()->payload['delete_files'])->toBeTrue();
});

test('an admin changes the quality profile from the title page', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);
    Queue::fake([ExecuteActionRequest::class]);
    fakeWhisparrBrowserLibrary();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.whisparr.show', ['id' => 11], absolute: false))
        ->assertNoSmoke()
        ->click('[data-quality-profile-trigger]')
        ->click('[data-quality-profile-option="2"]')
        ->assertSee('Quality profile updated.');

    expect(ActionRequest::query()->where('type', 'whisparr_set_quality_profile')->sole()->payload['quality_profile_id'])->toBe(2);
});
