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
    $manualImportResolver = resolve(ManualImportResolver::class);

    expect($manualImportResolver->toImportPayload([sonarrCandidate(['series' => []])], 'sonarr', 'd'))->toBe([]);
    expect($manualImportResolver->toImportPayload([sonarrCandidate(['episodes' => []])], 'sonarr', 'd'))->toBe([]);
    expect($manualImportResolver->toImportPayload([sonarrCandidate(['path' => null])], 'sonarr', 'd'))->toBe([]);
    expect($manualImportResolver->toImportPayload([sonarrCandidate(['quality' => null])], 'sonarr', 'd'))->toBe([]);
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

test('assess reports a clean, fully-mapped set as fully mapped', function (): void {
    $assessment = resolve(ManualImportResolver::class)->assess([sonarrCandidate()], 'sonarr', 'd');

    expect($assessment['fully_mapped'])->toBeTrue();
    expect($assessment['importable'])->toBe(1);
    expect($assessment['total'])->toBe(1);
});

test('assess ignores rejection text — a mapped-but-rejected file is still fully mapped', function (): void {
    // Rejection interpretation is the agent's job; assess() is structural only.
    $assessment = resolve(ManualImportResolver::class)->assess([
        sonarrCandidate(['rejections' => [['reason' => 'Not an upgrade for existing episode file(s)', 'type' => 'permanent']]]),
    ], 'sonarr', 'd');

    expect($assessment['fully_mapped'])->toBeTrue();
    expect($assessment['importable'])->toBe(1);
});

test('assess flags partial mappings as not fully mapped', function (): void {
    $assessment = resolve(ManualImportResolver::class)->assess([
        sonarrCandidate(),
        sonarrCandidate(['series' => []]), // unmappable
    ], 'sonarr', 'd');

    expect($assessment['fully_mapped'])->toBeFalse();
    expect($assessment['importable'])->toBe(1);
    expect($assessment['total'])->toBe(2);
});

test('assess flags nothing-importable', function (): void {
    $assessment = resolve(ManualImportResolver::class)->assess([], 'sonarr', 'd');

    expect($assessment['fully_mapped'])->toBeFalse();
    expect($assessment['importable'])->toBe(0);
});

test('describe exposes per-file mapping, episodes and raw rejections', function (): void {
    $files = resolve(ManualImportResolver::class)->describe([
        sonarrCandidate(['rejections' => [['reason' => 'Found matching series via grab history, but series was matched by series id. Automatic import is not possible.']]]),
        sonarrCandidate(['series' => [], 'episodes' => []]),
    ], 'sonarr');

    expect($files)->toHaveCount(2);
    expect($files[0]['mapped'])->toBeTrue();
    expect($files[0]['episodes'])->toBe(['S01E01']);
    expect($files[0]['rejections'][0])->toContain('matched by series id');
    expect($files[1]['mapped'])->toBeFalse();
});

function mappedSonarrCandidate(string $path): array
{
    return sonarrCandidate(['path' => $path]);
}

function unmappedCandidate(string $path): array
{
    return sonarrCandidate(['path' => $path, 'series' => [], 'episodes' => []]);
}

dataset('sample candidates', [
    'Sonarr Sample rejection' => [['path' => '/dl/Show.S01E01/show.s01e01.mkv', 'rejections' => [['reason' => 'Sample']]], true],
    'basename token' => [['path' => '/dl/Show.S01E01/show.s01e01-sample.mkv', 'rejections' => []], true],
    'Sample folder' => [['path' => '/dl/Show.S01E01/Sample/show.s01e01.mkv', 'rejections' => []], true],
    'undetermined is not a sample' => [['path' => '/dl/Show.S01E01/show.s01e01.mkv', 'rejections' => [['reason' => 'Unable to determine if file is a sample']]], false],
    'word inside a title' => [['path' => '/dl/Samples.and.Sinners.S01E01/samples.and.sinners.s01e01.mkv', 'rejections' => []], false],
    'regular file' => [['path' => '/dl/Show.S01E01/show.s01e01.mkv', 'rejections' => []], false],
]);

test('isSample recognises samples', function (array $candidate, bool $expected): void {
    expect(resolve(ManualImportResolver::class)->isSample($candidate))->toBe($expected);
})->with('sample candidates');

test('a mapped episode with a sample beside it is fully mapped and imports only the episode', function (): void {
    $candidates = [mappedSonarrCandidate('/dl/x/show.s01e01.mkv'), mappedSonarrCandidate('/dl/x/Sample/show.s01e01.mkv')];
    $manualImportResolver = resolve(ManualImportResolver::class);

    expect($manualImportResolver->toImportPayload($candidates, 'sonarr', 'D'))->toHaveCount(1)
        ->and($manualImportResolver->assess($candidates, 'sonarr', 'D'))->toMatchArray([
            'total' => 1, 'importable' => 1, 'fully_mapped' => true,
            'skipped' => [['path' => '/dl/x/Sample/show.s01e01.mkv', 'why' => 'sample']],
        ]);
});

test('an explicit exclusion removes a file, an unknown path is ignored, excluding everything leaves nothing importable', function (): void {
    $candidates = [mappedSonarrCandidate('/dl/x/a.mkv'), unmappedCandidate('/dl/x/extra.mkv')];
    $manualImportResolver = resolve(ManualImportResolver::class);

    expect($manualImportResolver->assess($candidates, 'sonarr', 'D', ['/dl/x/extra.mkv', '/not/a/candidate.mkv']))
        ->toMatchArray(['total' => 1, 'importable' => 1, 'fully_mapped' => true])
        ->and($manualImportResolver->assess($candidates, 'sonarr', 'D', ['/dl/x/a.mkv', '/dl/x/extra.mkv']))
        ->toMatchArray(['total' => 0, 'importable' => 0, 'fully_mapped' => false]);
});

test('describe marks a sample', function (): void {
    $described = resolve(ManualImportResolver::class)->describe([
        mappedSonarrCandidate('/dl/x/show.s01e01.mkv'),
        mappedSonarrCandidate('/dl/x/Sample/show.s01e01.mkv'),
    ], 'sonarr');

    expect($described[0]['sample'])->toBeFalse()
        ->and($described[1]['sample'])->toBeTrue();
});
