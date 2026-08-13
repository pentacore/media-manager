<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use App\Settings\MediaReplacementSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Cache::flush();
});

function replacementInspectParams(ServiceConnection $connection, array $overrides = []): array
{
    return [
        'service' => 'radarr',
        'service_connection_id' => $connection->id,
        'item_id' => 10,
        ...$overrides,
    ];
}

/**
 * Radarr movie-with-file fixture, lifted from
 * tests/Feature/Services/MediaReplacement/MediaFileInspectorTest.php so the
 * controller test exercises the real HTTP surface the inspector hits.
 */
function fakeRadarrMovieWithFile(int $movieId, int $fileId): void
{
    Http::fake([
        "radarr.local:7878/api/v3/movie/{$movieId}" => Http::response([
            'id' => $movieId, 'title' => 'A Movie', 'movieFileId' => $fileId,
        ]),
        "radarr.local:7878/api/v3/moviefile/{$fileId}" => Http::response([
            'id' => $fileId,
            'movieId' => $movieId,
            'sceneName' => 'A.Movie.2026.1080p.BluRay',
            'releaseGroup' => 'GROUP',
            'quality' => ['quality' => ['name' => 'Bluray-1080p']],
            'mediaInfo' => ['subtitles' => 'English / Swedish'],
        ]),
        'radarr.local:7878/api/v3/history*' => Http::response([
            'records' => [
                ['id' => 777, 'eventType' => 'grabbed', 'movieId' => $movieId, 'downloadId' => 'XYZ'],
            ],
        ]),
    ]);
}

function fakeRadarrMovieWithoutFile(int $movieId): void
{
    Http::fake([
        "radarr.local:7878/api/v3/movie/{$movieId}" => Http::response([
            'id' => $movieId, 'title' => 'A Movie',
        ]),
    ]);
}

/**
 * Fakes the Radarr native release search and configures a guidance rule that
 * confirms it, so ReplacementCandidateFinder::rank() actually produces a
 * ranked candidate instead of excluding the release for missing subtitle
 * evidence. Shape lifted from
 * tests/Feature/Services/MediaReplacement/ReplacementCandidateFinderTest.php.
 */
function fakeRadarrReleases(int $movieId): void
{
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_enabled' => false,
        'automatic_selection_threshold' => 90,
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null],
        'season_pack_policy' => 'approval_required',
        'guidance' => [
            'anime' => ['notes' => '', 'rules' => []],
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => [
                'notes' => '',
                'rules' => [[
                    'name' => 'Trusted',
                    'enabled' => true,
                    'strength' => 'guarantee',
                    'languages' => ['English'],
                    'conditions' => [['field' => 'title', 'value' => 'CR']],
                ]],
            ],
        ],
    ]);

    Http::fake([
        'radarr.local:7878/api/v3/release*' => Http::response([[
            'guid' => 'guid-1',
            'indexerId' => 10,
            'title' => 'A.Movie.2026.CR.1080p.BluRay',
            'releaseGroup' => 'GROUP',
            'movieId' => $movieId,
            'episodeIds' => [],
            'downloadAllowed' => true,
            'rejections' => [],
            'fullSeason' => false,
            'customFormats' => [],
            'customFormatScore' => 10,
            'qualityWeight' => 100,
            'seeders' => 5,
            'ageMinutes' => 60,
            'downloadUrl' => 'https://secret.example/download',
            'magnetUrl' => 'magnet:?xt=secret',
        ]]),
    ]);
}

test('viewers cannot inspect', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);

    $this->actingAs(User::factory()->create()) // default role is Viewer — no named state exists for it
        ->getJson(route('media.replacement.inspect', replacementInspectParams($connection)))
        ->assertForbidden();
});

test('unknown, inactive, or wrong-type connections are rejected without fallback', function (): void {
    $member = User::factory()->member()->create();
    $inactive = ServiceConnection::factory()->radarr()->inactive()->create();
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);

    $this->actingAs($member)
        ->getJson(route('media.replacement.inspect', replacementInspectParams($inactive)))
        ->assertUnprocessable();

    $this->actingAs($member)
        ->getJson(route('media.replacement.inspect', replacementInspectParams($sonarr))) // type mismatch: service=radarr
        ->assertUnprocessable();

    $this->actingAs($member)
        ->getJson(route('media.replacement.inspect', replacementInspectParams($inactive, ['service_connection_id' => 999_999])))
        ->assertUnprocessable();
});

test('members get snapshot, fingerprint and required languages', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    fakeRadarrMovieWithFile(movieId: 10, fileId: 5);

    $response = $this->actingAs(User::factory()->member()->create())
        ->getJson(route('media.replacement.inspect', replacementInspectParams($connection)))
        ->assertOk();

    expect($response->json('snapshot.display_name'))->not->toBeNull()
        ->and($response->json('snapshot.service'))->toBe('radarr')
        ->and($response->json('snapshot.service_connection_id'))->toBe($connection->id)
        ->and($response->json('fingerprint'))->toHaveLength(64)
        ->and($response->json('required_languages'))->toBeArray();
});

test('ambiguous targets return a structured error', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    fakeRadarrMovieWithoutFile(movieId: 10);

    $this->actingAs(User::factory()->member()->create())
        ->getJson(route('media.replacement.inspect', replacementInspectParams($connection)))
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $m): bool => $m !== '');
});

test('a Sonarr/Radarr connection failure surfaces as a 502', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    Http::fake(['http://radarr.local:7878/*' => Http::failedConnection()]);

    $this->actingAs(User::factory()->member()->create())
        ->getJson(route('media.replacement.inspect', replacementInspectParams($connection)))
        ->assertStatus(502)
        ->assertJsonPath('message', 'Sonarr/Radarr is unreachable.');
});

test('candidates returns the ranked list for a valid target', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    fakeRadarrMovieWithFile(movieId: 10, fileId: 5);
    fakeRadarrReleases(movieId: 10); // ranked-list fixture from ReplacementCandidateFinder tests

    $response = $this->actingAs(User::factory()->member()->create())
        ->getJson(route('media.replacement.candidates', replacementInspectParams($connection)))
        ->assertOk();

    expect($response->json('candidates.0.fingerprint'))->not->toBeNull()
        ->and($response->json('candidates.0.confidence'))->not->toBeNull()
        ->and($response->json('candidates.0.matched_rules'))->toBeArray()
        ->and($response->json('candidates.0.season_pack'))->toBeBool()
        ->and($response->json('effective_languages'))->toBeArray()
        ->and($response->json('excluded'))->toBeArray();
});

test('a Sonarr/Radarr connection failure surfaces as a 502 for candidates', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    Http::fake(['http://radarr.local:7878/*' => Http::failedConnection()]);

    $this->actingAs(User::factory()->member()->create())
        ->getJson(route('media.replacement.candidates', replacementInspectParams($connection)))
        ->assertStatus(502)
        ->assertJsonPath('message', 'Sonarr/Radarr is unreachable.');
});

test('a second candidates request within the cache TTL does not re-hit the release search', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    fakeRadarrMovieWithFile(movieId: 10, fileId: 5);
    fakeRadarrReleases(movieId: 10);

    $member = User::factory()->member()->create();
    $params = replacementInspectParams($connection);

    $this->actingAs($member)
        ->getJson(route('media.replacement.candidates', $params))
        ->assertOk();

    $this->actingAs($member)
        ->getJson(route('media.replacement.candidates', $params))
        ->assertOk();

    // The load-bearing assertion: the release search itself — the thing this
    // endpoint's cache is meant to protect — was hit exactly once across both
    // calls, regardless of how any other endpoint's own caching behaves.
    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/api/v3/release')))
        ->toHaveCount(1);

    // Each inspect() round-trips the moviefile and history endpoints fresh
    // every call (2 requests), while RadarrClient::getMovieById is cached by
    // RadarrCache for the test's duration (1 request total across both
    // calls) and the release search is cached by fingerprint in the
    // controller (1 request total). Two candidates requests therefore make
    // 1 (movie) + 2 + 2 (moviefile/history) + 1 (release) = 6, not 8.
    Http::assertSentCount(6);
});
