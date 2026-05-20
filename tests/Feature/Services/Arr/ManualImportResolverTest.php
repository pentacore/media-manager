<?php

declare(strict_types=1);

use App\Services\Arr\ManualImportResolver;

function sonarrCandidate(array $overrides = []): array
{
    return array_replace([
        'path' => '/downloads/show.s01e01.mkv',
        'folderName' => 'show.s01e01',
        'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
        'languages' => [['name' => 'English']],
        'releaseGroup' => 'GRP',
        'series' => ['id' => 5, 'title' => 'Demo'],
        'episodes' => [['id' => 11, 'seasonNumber' => 1, 'episodeNumber' => 1]],
        'rejections' => [],
    ], $overrides);
}

test('toImportPayload maps a clean sonarr candidate', function (): void {
    $files = resolve(ManualImportResolver::class)->toImportPayload([sonarrCandidate()], 'sonarr', 'dl-1');

    expect($files)->toHaveCount(1);
    expect($files[0]['seriesId'])->toBe(5);
    expect($files[0]['episodeIds'])->toBe([11]);
    expect($files[0]['downloadId'])->toBe('dl-1');
});

test('toImportPayload drops sonarr candidates without a series or episodes', function (): void {
    $resolver = resolve(ManualImportResolver::class);

    expect($resolver->toImportPayload([sonarrCandidate(['series' => []])], 'sonarr', 'd'))->toBe([]);
    expect($resolver->toImportPayload([sonarrCandidate(['episodes' => []])], 'sonarr', 'd'))->toBe([]);
    expect($resolver->toImportPayload([sonarrCandidate(['path' => null])], 'sonarr', 'd'))->toBe([]);
    expect($resolver->toImportPayload([sonarrCandidate(['quality' => null])], 'sonarr', 'd'))->toBe([]);
});

test('toImportPayload maps a radarr candidate by movieId', function (): void {
    $files = resolve(ManualImportResolver::class)->toImportPayload([[
        'path' => '/downloads/movie.mkv',
        'quality' => ['quality' => ['name' => 'Bluray-1080p']],
        'movie' => ['id' => 99],
    ]], 'radarr', 'dl-2');

    expect($files)->toHaveCount(1);
    expect($files[0]['movieId'])->toBe(99);
});

test('assess reports a clean, fully-mapped set as unambiguous', function (): void {
    $assessment = resolve(ManualImportResolver::class)->assess([sonarrCandidate()], 'sonarr', 'd');

    expect($assessment['ambiguous'])->toBeFalse();
    expect($assessment['importable'])->toBe(1);
    expect($assessment['total'])->toBe(1);
    expect($assessment['rejected'])->toBe(0);
});

test('assess flags rejections as ambiguous', function (): void {
    $assessment = resolve(ManualImportResolver::class)->assess([
        sonarrCandidate(['rejections' => [['reason' => 'Unknown series', 'type' => 'permanent']]]),
    ], 'sonarr', 'd');

    expect($assessment['ambiguous'])->toBeTrue();
    expect($assessment['rejected'])->toBe(1);
});

test('assess flags partial mappings as ambiguous', function (): void {
    $assessment = resolve(ManualImportResolver::class)->assess([
        sonarrCandidate(),
        sonarrCandidate(['series' => []]), // unmappable
    ], 'sonarr', 'd');

    expect($assessment['ambiguous'])->toBeTrue();
    expect($assessment['importable'])->toBe(1);
    expect($assessment['total'])->toBe(2);
});

test('assess flags nothing-importable', function (): void {
    $assessment = resolve(ManualImportResolver::class)->assess([], 'sonarr', 'd');

    expect($assessment['ambiguous'])->toBeTrue();
    expect($assessment['importable'])->toBe(0);
});
