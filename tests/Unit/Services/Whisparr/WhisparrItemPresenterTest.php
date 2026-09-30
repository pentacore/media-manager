<?php

declare(strict_types=1);

use App\Enums\WhisparrVersion;
use App\Services\Whisparr\WhisparrItemPresenter;

/**
 * @return array<string, mixed>
 */
function neutralWhisparrRow(int $id, string $kind): array
{
    return [
        'id' => $id, 'kind' => $kind, 'title' => sprintf('#%d', $id), 'year' => null, 'monitored' => false,
        'has_file' => false, 'size_bytes' => 0, 'poster_url' => null, 'quality_profile_id' => null,
    ];
}

test('a v3 movie becomes a movie row with its remote poster', function (): void {
    $row = new WhisparrItemPresenter()->row(WhisparrVersion::V3, [
        'id' => 11, 'title' => 'Aurora Scene', 'year' => 2024, 'monitored' => true, 'hasFile' => true,
        'sizeOnDisk' => 2_000_000_000, 'qualityProfileId' => 3, 'apiKey' => 'never-copied',
        'images' => [
            ['coverType' => 'fanart', 'remoteUrl' => 'https://img.example/fan.jpg'],
            ['coverType' => 'poster', 'remoteUrl' => 'https://img.example/poster.jpg', 'url' => '/MediaCover/11/poster.jpg'],
        ],
    ]);

    expect($row)->toBe([
        'id' => 11, 'kind' => 'movie', 'title' => 'Aurora Scene', 'year' => 2024, 'monitored' => true,
        'has_file' => true, 'size_bytes' => 2_000_000_000, 'poster_url' => 'https://img.example/poster.jpg', 'quality_profile_id' => 3,
    ]);
});

test('a v2 series becomes a site row with statistics-derived file state', function (): void {
    $row = new WhisparrItemPresenter()->row(WhisparrVersion::V2, [
        'id' => 5, 'title' => 'Site Five', 'year' => 2019, 'monitored' => false, 'qualityProfileId' => 2,
        'statistics' => ['episodeFileCount' => 4, 'sizeOnDisk' => 9_000], 'images' => [],
    ]);

    expect($row)->toBe([
        'id' => 5, 'kind' => 'site', 'title' => 'Site Five', 'year' => 2019, 'monitored' => false,
        'has_file' => true, 'size_bytes' => 9_000, 'poster_url' => null, 'quality_profile_id' => 2,
    ]);
});

test('every key is present with a neutral value when upstream omits the optional fields', function (WhisparrVersion $whisparrVersion, string $kind): void {
    $row = new WhisparrItemPresenter()->row($whisparrVersion, ['id' => 9]);

    expect(array_keys($row ?? []))->toBe(['id', 'kind', 'title', 'year', 'monitored', 'has_file', 'size_bytes', 'poster_url', 'quality_profile_id'])
        ->and($row)->toBe(neutralWhisparrRow(9, $kind));
})->with([
    'v2' => [WhisparrVersion::V2, 'site'],
    'v3' => [WhisparrVersion::V3, 'movie'],
]);

test('wrongly typed upstream values are neutralised, never passed through', function (WhisparrVersion $whisparrVersion, string $kind): void {
    $row = new WhisparrItemPresenter()->row($whisparrVersion, [
        'id' => 9, 'title' => ['x'], 'year' => '2020', 'monitored' => 'yes', 'hasFile' => 1,
        'statistics' => 'n/a', 'images' => 'poster', 'qualityProfileId' => 0, 'sizeOnDisk' => -5,
    ]);

    expect($row)->toBe(neutralWhisparrRow(9, $kind));
})->with([
    'v2' => [WhisparrVersion::V2, 'site'],
    'v3' => [WhisparrVersion::V3, 'movie'],
]);

test('a numeric string id is coerced to int, a non-numeric or non-positive one is unusable', function (WhisparrVersion $whisparrVersion, string $kind): void {
    $whisparrItemPresenter = new WhisparrItemPresenter;

    expect($whisparrItemPresenter->row($whisparrVersion, ['id' => '12', 'title' => 'String Id']))
        ->toBe([
            'id' => 12, 'kind' => $kind, 'title' => 'String Id', 'year' => null, 'monitored' => false,
            'has_file' => false, 'size_bytes' => 0, 'poster_url' => null, 'quality_profile_id' => null,
        ])
        ->and($whisparrItemPresenter->row($whisparrVersion, ['id' => 'abc']))->toBeNull()
        ->and($whisparrItemPresenter->row($whisparrVersion, ['id' => '-1']))->toBeNull()
        ->and($whisparrItemPresenter->row($whisparrVersion, ['id' => '0']))->toBeNull();
})->with([
    'v2' => [WhisparrVersion::V2, 'site'],
    'v3' => [WhisparrVersion::V3, 'movie'],
]);

test('rows skip entries without a usable id', function (): void {
    $rows = new WhisparrItemPresenter()->rows(WhisparrVersion::V3, [
        ['id' => 1, 'title' => 'A'], ['title' => 'no id'], 'garbage', ['id' => '2', 'title' => 'string id'], ['id' => 0], ['id' => 'abc'],
    ]);

    expect(array_column($rows, 'id'))->toBe([1, 2]);
});

test('detail adds path and overview for both versions', function (WhisparrVersion $whisparrVersion): void {
    $whisparrItemPresenter = new WhisparrItemPresenter;

    expect($whisparrItemPresenter->detail($whisparrVersion, ['id' => 3, 'title' => 'T', 'path' => '/data/whisparr/T', 'overview' => 'About T']))
        ->toMatchArray(['path' => '/data/whisparr/T', 'overview' => 'About T'])
        ->and($whisparrItemPresenter->detail($whisparrVersion, ['id' => 3]))
        ->toMatchArray(['path' => null, 'overview' => null])
        ->and($whisparrItemPresenter->detail($whisparrVersion, ['title' => 'no id']))
        ->toBeNull();
})->with([WhisparrVersion::V2, WhisparrVersion::V3]);

test('v2 scenes are grouped by year, newest year and newest scene first', function (): void {
    $groups = new WhisparrItemPresenter()->sceneGroups([
        ['id' => 1, 'seasonNumber' => 2023, 'title' => 'Old', 'airDate' => '2023-02-01', 'hasFile' => true, 'monitored' => true],
        ['id' => 2, 'seasonNumber' => 2024, 'title' => 'New A', 'airDate' => '2024-01-05', 'hasFile' => false, 'monitored' => true],
        ['id' => 3, 'seasonNumber' => 2024, 'title' => 'New B', 'airDate' => '2024-03-09', 'hasFile' => false, 'monitored' => false],
        ['id' => 4, 'seasonNumber' => 0, 'airDate' => '2022-06-01'],
        ['id' => 5],
        ['title' => 'no id'],
        'garbage',
    ]);

    expect(array_column($groups, 'year'))->toBe([2024, 2023, 2022, 0])
        ->and(array_column($groups[0]['scenes'], 'id'))->toBe([3, 2])
        ->and($groups[0]['scenes'][0])->toBe(['id' => 3, 'title' => 'New B', 'air_date' => '2024-03-09', 'has_file' => false, 'monitored' => false])
        ->and($groups[3]['scenes'][0])->toBe(['id' => 5, 'title' => null, 'air_date' => null, 'has_file' => false, 'monitored' => false]);
});
