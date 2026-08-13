<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
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
