<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15T12:00:00Z'));
    $this->seed(ActionTypeConfigSeeder::class);
    $this->sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    $this->radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    Http::fake([
        'sonarr.local:8989/api/v3/calendar*' => Http::response([[
            'id' => 70, 'seriesId' => 7, 'seasonNumber' => 1, 'episodeNumber' => 2, 'title' => 'Half Loop',
            'airDateUtc' => '2026-09-10T02:00:00Z', 'hasFile' => false, 'monitored' => true,
            'series' => [
                'title' => 'Severance', 'monitored' => true,
                // A poster is required so CalendarItem renders an <img> in the
                // agenda's full (non-compact) view — without one, Poster's
                // text fallback duplicates the title text ("Severance"),
                // tripping Playwright's strict-mode getByText() match.
                'images' => [['coverType' => 'poster', 'remoteUrl' => 'https://img.test/severance.jpg']],
            ],
        ]]),
        'sonarr.local:8989/api/v3/command' => Http::response(['id' => 1], 201),
        'sonarr.local:8989/api/v3/series/7' => Http::response(['id' => 7, 'title' => 'Severance', 'year' => 2022]),
        'radarr.local:7878/api/v3/calendar*' => Http::response([[
            'id' => 10, 'title' => 'Dune', 'monitored' => true, 'hasFile' => false, 'digitalRelease' => '2026-09-24T00:00:00Z',
            'images' => [['coverType' => 'poster', 'remoteUrl' => 'https://img.test/dune.jpg']],
        ]]),
    ]);
});

test('the month grid places items on their day and the agenda lists them', function (): void {
    $this->actingAs(User::factory()->create());

    visit(route('media.calendar.index', ['month' => '2026-09'], absolute: false))
        ->assertNoSmoke()
        ->click('[data-calendar-view="month"]')
        ->assertSeeIn('[data-calendar-day="2026-09-10"]', 'Severance')
        ->assertSeeIn('[data-calendar-day="2026-09-24"]', 'Dune')
        ->click('[data-calendar-view="agenda"]')
        ->assertSeeIn('[data-calendar-agenda]', 'S01E02')
        ->assertSeeIn('[data-calendar-agenda]', 'Dune')
        ->click('[data-calendar-filter="movies"]')
        ->assertDontSeeIn('[data-calendar-agenda]', 'Dune')
        ->assertCount('[data-search-now]', 0);
});

test('prev/next navigation changes the month and its items', function (): void {
    $this->actingAs(User::factory()->create());

    visit(route('media.calendar.index', ['month' => '2026-09'], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-calendar-month-label]', 'September 2026')
        ->click('[data-calendar-view="agenda"]')
        ->assertSeeIn('[data-calendar-agenda]', 'Severance')
        ->click('[data-calendar-prev]')
        ->assertSeeIn('[data-calendar-month-label]', 'August 2026')
        ->assertDontSeeIn('[data-calendar-agenda]', 'Severance')
        ->click('[data-calendar-next]')
        ->click('[data-calendar-next]')
        ->assertSeeIn('[data-calendar-month-label]', 'October 2026')
        ->assertDontSeeIn('[data-calendar-agenda]', 'Severance');
});

test('the monitored-only filter hides unmonitored items', function (): void {
    $this->sonarr->update(['url' => 'http://sonarr-monitored-filter.local:8989']);
    Http::fake([
        'sonarr-monitored-filter.local:8989/api/v3/calendar*' => Http::response([[
            'id' => 90, 'seriesId' => 9, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Pilot',
            'airDateUtc' => '2026-09-11T02:00:00Z', 'hasFile' => false, 'monitored' => false,
            'series' => [
                'title' => 'Unmonitored Show', 'monitored' => false,
                'images' => [['coverType' => 'poster', 'remoteUrl' => 'https://img.test/unmonitored.jpg']],
            ],
        ]]),
    ]);
    $this->actingAs(User::factory()->create());

    visit(route('media.calendar.index', ['month' => '2026-09'], absolute: false))
        ->assertNoSmoke()
        ->click('[data-calendar-view="agenda"]')
        ->assertSeeIn('[data-calendar-agenda]', 'Severance')
        ->assertSeeIn('[data-calendar-agenda]', 'Unmonitored Show')
        ->click('[data-calendar-filter="monitored"]')
        ->assertSeeIn('[data-calendar-agenda]', 'Severance')
        ->assertDontSeeIn('[data-calendar-agenda]', 'Unmonitored Show');
});

test("a movie item's Search and Monitor controls post the movie path", function (): void {
    Http::fake([
        'radarr.local:7878/api/v3/movie/10' => Http::sequence()
            ->push(['id' => 10, 'title' => 'Dune', 'monitored' => true])
            ->push(['id' => 10, 'title' => 'Dune', 'monitored' => false]),
    ]);
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.calendar.index', ['month' => '2026-09'], absolute: false))
        ->assertNoSmoke()
        ->click('[data-calendar-view="agenda"]')
        ->assertSeeIn(sprintf('[data-calendar-item="radarr:%d:10"]', $this->radarr->id), 'Dune')
        ->click(sprintf('[data-calendar-item="radarr:%d:10"] [data-search-now]', $this->radarr->id))
        ->assertSee('Search started.');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v3/command')
        && $request['name'] === 'MoviesSearch'
        && $request['movieIds'] === [10]);

    $webpage->click(sprintf('[data-calendar-item="radarr:%d:10"] [data-monitor-toggle]', $this->radarr->id))
        ->assertSee('Monitoring updated.');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/movie/10')
        && $request->method() === 'PUT'
        && $request['monitored'] === false);
});

test('a member searches an episode inline from the agenda', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.calendar.index', ['month' => '2026-09'], absolute: false))
        ->assertNoSmoke()
        ->click('[data-calendar-view="agenda"]')
        ->click(sprintf('[data-calendar-item="sonarr:%d:70"] [data-search-now]', $this->sonarr->id))
        ->assertSee('Search started.');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v3/command') && $request['name'] === 'EpisodeSearch' && $request['episodeIds'] === [70]);
});

test('a member toggles an episode of an unmonitored series by its own monitored flag', function (): void {
    // Http::fake keeps the first matching stub, so the beforeEach Sonarr
    // calendar stub cannot be overridden; move Sonarr to a host whose stubs
    // describe an unmonitored series with a monitored episode instead.
    $this->sonarr->update(['url' => 'http://sonarr-unmonitored.local:8989']);
    Http::fake([
        'sonarr-unmonitored.local:8989/api/v3/calendar*' => Http::response([[
            'id' => 80, 'seriesId' => 8, 'seasonNumber' => 1, 'episodeNumber' => 1, 'title' => 'Pilot',
            'airDateUtc' => '2026-09-12T02:00:00Z', 'hasFile' => false, 'monitored' => true,
            'series' => [
                'title' => 'Old Show', 'monitored' => false,
                'images' => [['coverType' => 'poster', 'remoteUrl' => 'https://img.test/old-show.jpg']],
            ],
        ]]),
        'sonarr-unmonitored.local:8989/api/v3/episode/monitor' => Http::response([], 202),
        'sonarr-unmonitored.local:8989/api/v3/series/8' => Http::response(['id' => 8, 'title' => 'Old Show', 'year' => 2019]),
    ]);
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.calendar.index', ['month' => '2026-09'], absolute: false))
        ->assertNoSmoke()
        ->click('[data-calendar-view="agenda"]')
        ->assertAttribute(sprintf('[data-calendar-item="sonarr:%d:80"] [data-monitor-toggle]', $this->sonarr->id), 'aria-pressed', 'true')
        ->click(sprintf('[data-calendar-item="sonarr:%d:80"] [data-monitor-toggle]', $this->sonarr->id))
        ->assertSee('Monitoring updated.');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v3/episode/monitor') && $request['episodeIds'] === [80] && $request['monitored'] === false);
});

test('a Radarr movie date buckets by its UTC calendar day, unaffected by the viewer timezone', function (): void {
    // Dune's digitalRelease is midnight UTC on 2026-09-24 (see beforeEach).
    // Converted into a timezone west of UTC it would fall on 2026-09-23 —
    // the movie must still land on the 24th.
    $this->actingAs(User::factory()->create(['preferences' => ['timezone' => 'America/Los_Angeles']]));

    visit(route('media.calendar.index', ['month' => '2026-09'], absolute: false))
        ->assertNoSmoke()
        ->click('[data-calendar-view="month"]')
        ->assertSeeIn('[data-calendar-day="2026-09-24"]', 'Dune')
        ->assertDontSeeIn('[data-calendar-day="2026-09-23"]', 'Dune');
});

test('an unreachable service is named in a banner while the rest still shows', function (): void {
    // Http::fake keeps the first matching stub, so the beforeEach Radarr
    // calendar stub cannot be overridden; move Radarr to a host whose only
    // stub fails instead.
    ServiceConnection::query()->where('type', 'radarr')->update(['url' => 'http://radarr-down.local:7878']);
    Http::fake(['radarr-down.local:7878/*' => Http::response([], 503)]);
    $this->actingAs(User::factory()->create());

    visit(route('media.calendar.index', ['month' => '2026-09'], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-calendar-failures]', 'Radarr')
        ->click('[data-calendar-view="agenda"]')
        ->assertSeeIn('[data-calendar-agenda]', 'Severance');
});
