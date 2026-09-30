<?php

declare(strict_types=1);

use App\Services\Seerr\SeerrTitlePresenter;

test('it maps a search hit to a title row', function (): void {
    $row = new SeerrTitlePresenter()->title([
        'id' => 95396, 'mediaType' => 'tv', 'name' => 'Severance', 'firstAirDate' => '2022-02-17',
        'posterPath' => '/p.jpg', 'backdropPath' => '/b.jpg', 'overview' => 'Work.', 'mediaInfo' => ['status' => 4],
    ]);

    expect($row)->toBe([
        'tmdb_id' => 95396,
        'media_type' => 'tv',
        'title' => 'Severance',
        'year' => 2022,
        'poster_path' => '/p.jpg',
        'backdrop_path' => '/b.jpg',
        'overview' => 'Work.',
        'release_date' => '2022-02-17',
        'status' => 'partially_available',
    ]);
});

test('it maps Seerr media status to the five title states', function (?int $status, string $expected): void {
    expect(new SeerrTitlePresenter()->status($status === null ? null : ['status' => $status]))->toBe($expected);
})->with([
    'never requested' => [null, 'none'],
    'unknown' => [1, 'none'],
    'pending' => [2, 'pending'],
    'processing' => [3, 'requested'],
    'partially available' => [4, 'partially_available'],
    'available' => [5, 'available'],
    'deleted' => [7, 'none'],
]);

test('results drops people and hits without an id or title and applies a fallback type', function (): void {
    $rows = new SeerrTitlePresenter()->results(['results' => [
        ['id' => 1, 'mediaType' => 'person', 'name' => 'Someone'],
        ['id' => 0, 'mediaType' => 'movie', 'title' => 'Broken'],
        ['id' => 2, 'title' => 'Dune', 'releaseDate' => '2021-10-22'],
    ]], 'movie');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['tmdb_id'])->toBe(2)
        ->and($rows[0]['media_type'])->toBe('movie')
        ->and($rows[0]['status'])->toBe('none');
});

test('detail marks available and requested tv seasons as not requestable and skips specials', function (): void {
    $detail = new SeerrTitlePresenter()->detail('tv', [
        'id' => 95396, 'name' => 'Severance', 'firstAirDate' => '2022-02-17', 'voteAverage' => 8.43,
        'episodeRunTime' => [55], 'numberOfSeasons' => 3,
        'seasons' => [
            ['seasonNumber' => 0, 'name' => 'Specials', 'episodeCount' => 2],
            ['seasonNumber' => 1, 'name' => 'Season 1', 'episodeCount' => 9],
            ['seasonNumber' => 2, 'name' => 'Season 2', 'episodeCount' => 10],
            ['seasonNumber' => 3, 'name' => 'Season 3', 'episodeCount' => 10],
        ],
        'mediaInfo' => [
            'status' => 4,
            'seasons' => [['seasonNumber' => 1, 'status' => 5]],
            'requests' => [
                ['status' => 1, 'seasons' => [['seasonNumber' => 2]]],
                ['status' => 3, 'seasons' => [['seasonNumber' => 3]]],
            ],
        ],
    ]);

    expect($detail['rating'])->toBe(8.4)
        ->and($detail['runtime'])->toBe(55)
        ->and($detail['season_count'])->toBe(3)
        ->and($detail['seasons'])->toBe([
            ['season_number' => 1, 'name' => 'Season 1', 'episode_count' => 9, 'status' => 'available', 'requestable' => false],
            ['season_number' => 2, 'name' => 'Season 2', 'episode_count' => 10, 'status' => 'pending', 'requestable' => false],
            ['season_number' => 3, 'name' => 'Season 3', 'episode_count' => 10, 'status' => 'none', 'requestable' => true],
        ]);
});

test('detail reads a movie runtime and has no seasons', function (): void {
    $detail = new SeerrTitlePresenter()->detail('movie', ['id' => 438631, 'title' => 'Dune', 'releaseDate' => '2021-10-22', 'runtime' => 155]);

    expect($detail['runtime'])->toBe(155)->and($detail['seasons'])->toBe([])->and($detail['season_count'])->toBeNull();
});
