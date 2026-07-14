<?php

declare(strict_types=1);

use App\Enums\MediaReplacementScope;
use App\Models\ServiceConnection;
use App\Services\MediaReplacement\ReplacementCandidateFinder;
use App\Settings\MediaReplacementSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nativeRelease(array $overrides = []): array
{
    return array_replace([
        'guid' => 'guid-1',
        'indexerId' => 10,
        'title' => 'Trusted.Anime.S01E01.CR',
        'releaseGroup' => 'SubsPlease',
        'episodeIds' => [101],
        'downloadAllowed' => true,
        'rejections' => [],
        'fullSeason' => false,
        'customFormats' => [['name' => 'Trusted', 'specifications' => ['bulky']]],
        'customFormatScore' => 10,
        'qualityWeight' => 100,
        'seeders' => 5,
        'ageMinutes' => 60,
        'downloadUrl' => 'https://secret.example/download',
        'magnetUrl' => 'magnet:?xt=secret',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sonarrTargetSnapshot(array $overrides = []): array
{
    return array_replace([
        'service' => 'sonarr',
        'scope' => 'anime',
        'series_id' => 42,
        'episode_ids' => [101],
        'installed_release' => 'old release',
    ], $overrides);
}

function configureCrGuarantee(): void
{
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_enabled' => false,
        'automatic_selection_threshold' => 90,
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null],
        'season_pack_policy' => 'approval_required',
        'guidance' => [
            'anime' => [
                'notes' => 'CR releases are trusted for English.',
                'rules' => [[
                    'name' => 'Crunchyroll English',
                    'enabled' => true,
                    'strength' => 'guarantee',
                    'languages' => ['English'],
                    'conditions' => [['field' => 'title', 'value' => 'CR']],
                ]],
            ],
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => ['notes' => '', 'rules' => []],
        ],
    ]);
}

test('finds Sonarr candidates using the native interactive search by series and episode', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);
    configureCrGuarantee();

    Http::fake([
        'sonarr.local:8989/api/v3/release*' => Http::response([nativeRelease()]),
    ]);

    $result = resolve(ReplacementCandidateFinder::class)->find(sonarrTargetSnapshot());

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/release')
        && str_contains($request->url(), 'seriesId=42')
        && str_contains($request->url(), 'episodeId=101'));

    expect($result['candidates'])->toHaveCount(1)
        ->and($result['candidates'][0]['confidence'])->toBe(98)
        ->and($result['effective_languages'])->toBe(['eng'])
        ->and($result['guidance']['notes'])->toBe('CR releases are trusted for English.');
});

test('never leaks download urls, magnet urls, or bulky custom format payloads', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);
    configureCrGuarantee();

    Http::fake(['sonarr.local:8989/api/v3/release*' => Http::response([nativeRelease()])]);

    $result = resolve(ReplacementCandidateFinder::class)->find(sonarrTargetSnapshot());
    $encoded = json_encode($result, JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain('secret.example')
        ->and($encoded)->not->toContain('magnet:')
        ->and($encoded)->not->toContain('specifications');
});

test('uses Radarr movieId for the native search', function (): void {
    ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878', 'api_key' => 'test', 'is_active' => true,
    ]);
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
                    'name' => 'Trusted', 'enabled' => true, 'strength' => 'guarantee', 'languages' => ['English'],
                    'conditions' => [['field' => 'title', 'value' => 'CR']],
                ]],
            ],
        ],
    ]);

    Http::fake(['radarr.local:7878/api/v3/release*' => Http::response([
        nativeRelease(['episodeIds' => [], 'movieId' => 88, 'title' => 'A.Movie.CR']),
    ])]);

    $result = resolve(ReplacementCandidateFinder::class)->find([
        'service' => 'radarr', 'scope' => 'movie', 'movie_id' => 88, 'installed_release' => 'old',
    ]);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/release')
        && str_contains($request->url(), 'movieId=88'));

    expect($result['candidates'])->toHaveCount(1);
});

test('a per-request language override wins without mutating settings', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);
    configureCrGuarantee();

    Http::fake(['sonarr.local:8989/api/v3/release*' => Http::response([nativeRelease()])]);

    $result = resolve(ReplacementCandidateFinder::class)->find(
        sonarrTargetSnapshot(),
        languageOverride: ['Swedish'],
    );

    expect($result['effective_languages'])->toBe(['swe'])
        ->and($result['candidates'])->toBe([])
        ->and($result['excluded']['subtitle_evidence'])->toBe(1)
        ->and(resolve(MediaReplacementSettings::class)->effectiveLanguages(MediaReplacementScope::Anime))->toBe(['eng']);
});

test('season_pack_policy never excludes a full-season pack', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_enabled' => false,
        'automatic_selection_threshold' => 90,
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null],
        'season_pack_policy' => 'never',
        'guidance' => [
            'anime' => [
                'notes' => '',
                'rules' => [[
                    'name' => 'CR', 'enabled' => true, 'strength' => 'guarantee', 'languages' => ['English'],
                    'conditions' => [['field' => 'title', 'value' => 'CR']],
                ]],
            ],
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => ['notes' => '', 'rules' => []],
        ],
    ]);

    Http::fake(['sonarr.local:8989/api/v3/release*' => Http::response([
        nativeRelease(['fullSeason' => true]),
    ])]);

    $result = resolve(ReplacementCandidateFinder::class)->find(sonarrTargetSnapshot());

    expect($result['candidates'])->toBe([])
        ->and($result['excluded']['season_pack_policy'])->toBe(1)
        ->and($result['automatic_candidate'])->toBeNull();
});

test('exposes an automatic candidate only when automation, uniqueness, and threshold all hold', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_enabled' => true,
        'automatic_selection_threshold' => 90,
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null],
        'season_pack_policy' => 'approval_required',
        'guidance' => [
            'anime' => [
                'notes' => '',
                'rules' => [[
                    'name' => 'CR', 'enabled' => true, 'strength' => 'guarantee', 'languages' => ['English'],
                    'conditions' => [['field' => 'title', 'value' => 'CR']],
                ]],
            ],
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => ['notes' => '', 'rules' => []],
        ],
    ]);

    Http::fake(['sonarr.local:8989/api/v3/release*' => Http::response([nativeRelease()])]);

    $result = resolve(ReplacementCandidateFinder::class)->find(sonarrTargetSnapshot());

    expect($result['unique_best'])->toBeTrue()
        ->and($result['automatic_candidate'])->not->toBeNull()
        ->and($result['automatic_candidate']['fingerprint'])->toBe($result['candidates'][0]['fingerprint']);
});

test('withholds an automatic candidate when automation is disabled', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);
    configureCrGuarantee();

    Http::fake(['sonarr.local:8989/api/v3/release*' => Http::response([nativeRelease()])]);

    $result = resolve(ReplacementCandidateFinder::class)->find(sonarrTargetSnapshot());

    expect($result['candidates'])->toHaveCount(1)
        ->and($result['automatic_candidate'])->toBeNull();
});
