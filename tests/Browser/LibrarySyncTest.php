<?php

declare(strict_types=1);

use App\Enums\WhisparrVersion;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * A real (data-uri) poster, so the card renders its <img> rather than the
 * Poster placeholder's hint text — which would otherwise duplicate the
 * title text below it and make assertSeeIn's getByText() ambiguous.
 *
 * @return array<int, array<string, string>>
 */
function librarySyncBrowserPoster(): array
{
    return [['coverType' => 'poster', 'remoteUrl' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7']];
}

/**
 * @return array<string, mixed>
 */
function librarySyncBrowserSeries(int $id, string $title): array
{
    return [
        'id' => $id, 'title' => $title, 'titleSlug' => sprintf('example-%d', $id), 'year' => 2024, 'status' => 'continuing',
        'monitored' => true, 'qualityProfileId' => 1, 'images' => librarySyncBrowserPoster(), 'seasons' => [],
        'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 0, 'episodeFileCount' => 0],
    ];
}

test('Sync reads Sonarr again, and a second Sync within half a minute says it just ran', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    // One stateful registration (Http::fake keeps the first matching stub);
    // the state lives on an object, never a by-reference variable.
    $state = new class
    {
        public int $calls = 0;
    };
    Http::fake([
        'sonarr.local:8989/api/v3/series' => function () use ($state) {
            $state->calls++;

            return Http::response($state->calls === 1
                ? [librarySyncBrowserSeries(1, 'Example Show')]
                : [librarySyncBrowserSeries(1, 'Example Show'), librarySyncBrowserSeries(2, 'Example Sequel')]);
        },
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
    ]);
    // A viewer: Sync is open to every role that can open the page.
    $this->actingAs(User::factory()->create());

    visit(route('media.series.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-series-card="1"]', 'Example Show')
        ->assertMissing('[data-series-card="2"]')
        ->click('[data-library-sync]')
        ->assertSee('Synced with Sonarr.')
        ->assertSeeIn('[data-series-card="2"]', 'Example Sequel')
        ->click('[data-library-sync]')
        ->assertSee('Sonarr was synced moments ago — try again in')
        ->assertNoSmoke();

    expect($state->calls)->toBe(2);
});

test('an admin syncs the Whisparr library', function (): void {
    ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V3)->create(['url' => 'http://whisparr.local:6969', 'api_key' => 'k']);
    $state = new class
    {
        public int $calls = 0;
    };
    Http::fake([
        'whisparr.local:6969/api/v3/movie' => function () use ($state) {
            $state->calls++;
            $titles = $state->calls === 1 ? ['Example Scene'] : ['Example Scene', 'Example Encore'];

            return Http::response(array_map(
                fn (string $title, int $index): array => ['id' => 21 + $index, 'title' => $title, 'year' => 2024, 'monitored' => true, 'hasFile' => false, 'qualityProfileId' => 1, 'images' => []],
                $titles,
                array_keys($titles),
            ));
        },
        'whisparr.local:6969/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'Any']]),
    ]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('media.whisparr.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-whisparr-card="21"] [data-whisparr-title]', 'Example Scene')
        ->assertMissing('[data-whisparr-card="22"]')
        ->click('[data-library-sync]')
        ->assertSee('Synced with Whisparr.')
        ->assertSeeIn('[data-whisparr-card="22"] [data-whisparr-title]', 'Example Encore')
        ->assertNoSmoke();
});
