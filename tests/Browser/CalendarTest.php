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
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
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

test('a member searches an episode inline from the agenda', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('media.calendar.index', ['month' => '2026-09'], absolute: false))
        ->assertNoSmoke()
        ->click('[data-calendar-view="agenda"]')
        ->click(sprintf('[data-calendar-item="sonarr:%d:70"] [data-search-now]', $this->sonarr->id))
        ->assertSee('Search started.');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v3/command') && $request['name'] === 'EpisodeSearch' && $request['episodeIds'] === [70]);
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
