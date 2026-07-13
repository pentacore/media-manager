<?php

declare(strict_types=1);

use App\Enums\AnimeFormat;

test('fromRaw maps known raw format strings onto the enum', function (?string $raw, AnimeFormat $animeFormat): void {
    expect(AnimeFormat::fromRaw($raw))->toBe($animeFormat);
})->with([
    'movie' => ['MOVIE', AnimeFormat::Movie],
    'ova' => ['OVA', AnimeFormat::Ova],
    'ona' => ['ONA', AnimeFormat::Ona],
    'special' => ['SPECIAL', AnimeFormat::Special],
    'music' => ['MUSIC', AnimeFormat::Music],
    'tv' => ['TV', AnimeFormat::Tv],
    'lowercase movie' => ['movie', AnimeFormat::Movie],
    'jikan Movie' => ['Movie', AnimeFormat::Movie],
]);

test('fromRaw falls back to TV for unknown, short, or null formats', function (?string $raw): void {
    expect(AnimeFormat::fromRaw($raw))->toBe(AnimeFormat::Tv);
})->with([
    'tv_short' => 'TV_SHORT',
    'unknown' => 'SOMETHING_ELSE',
    'empty' => '',
    'null' => null,
]);

test('seerrMediaType routes only movies to movie, everything else to tv', function (AnimeFormat $animeFormat, string $expected): void {
    expect($animeFormat->seerrMediaType())->toBe($expected);
})->with([
    [AnimeFormat::Movie, 'movie'],
    [AnimeFormat::Tv, 'tv'],
    [AnimeFormat::Ova, 'tv'],
    [AnimeFormat::Ona, 'tv'],
    [AnimeFormat::Special, 'tv'],
    [AnimeFormat::Music, 'tv'],
]);
