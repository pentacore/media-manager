<?php

declare(strict_types=1);

use App\Services\Bazarr\SubtitleItemMapper;
use App\Settings\MediaReplacementSettings;

beforeEach(function (): void {
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'global_languages' => ['English'],
    ]);
});

test('positive integers accept positive ints and digit strings only', function (mixed $value, ?int $expected): void {
    expect(resolve(SubtitleItemMapper::class)->positiveInteger($value))->toBe($expected);
})->with([
    'positive int' => [5, 5],
    'zero' => [0, null],
    'negative int' => [-3, null],
    'digit string' => ['42', 42],
    'zero string' => ['0', null],
    'signed string' => ['-4', null],
    'float' => [4.0, null],
    'null' => [null, null],
]);

test('a movie row falls back to a generic title for unusable upstream text and squishes long text', function (): void {
    $subtitleItemMapper = resolve(SubtitleItemMapper::class);

    expect($subtitleItemMapper->movieItem(['radarrId' => 801, 'title' => "\xB1\x31"])['title'])->toBe('Movie 801')
        ->and($subtitleItemMapper->movieItem(['radarrId' => 801, 'title' => '   '])['title'])->toBe('Movie 801')
        ->and($subtitleItemMapper->movieItem(['radarrId' => 801, 'title' => "  Two \n words  "])['title'])->toBe('Two words')
        ->and(mb_strlen($subtitleItemMapper->movieItem(['radarrId' => 801, 'title' => str_repeat('a', 300)])['title']))->toBe(253)
        ->and($subtitleItemMapper->movieItem(['radarrId' => 0, 'title' => 'No id']))->toBeNull();
});

test('a movie row lists its tracks, required languages and the ones still missing', function (): void {
    $item = resolve(SubtitleItemMapper::class)->movieItem([
        'radarrId' => 801,
        'title' => 'Example Movie',
        'subtitles' => [
            ['code3' => 'swe', 'path' => 'C:\\media\\Example.sv.srt', 'forced' => true],
            ['code3' => 'jpn', 'path' => null, 'embedded_track_id' => 3, 'hi' => true],
            'not-a-track',
            ['path' => '/media/no-language.srt'],
        ],
    ]);

    expect(array_keys($item))->toBe([
        'media_type', 'media_id', 'target_fingerprint', 'scope', 'title',
        'subtitle_tracks', 'required_languages', 'missing_languages', 'monitored',
    ])
        ->and($item['scope'])->toBe('movie')
        ->and($item['required_languages'])->toBe(['eng'])
        ->and($item['missing_languages'])->toBe(['eng'])
        ->and($item['monitored'])->toBeTrue()
        ->and($item['subtitle_tracks'])->toHaveCount(2)
        ->and($item['subtitle_tracks'][0])->toMatchArray([
            'display_name' => 'Example.sv.srt',
            'language' => 'swe',
            'kind' => 'external',
            'forced' => true,
            'hearing_impaired' => false,
        ])
        ->and($item['subtitle_tracks'][1])->toMatchArray([
            'display_name' => 'JPN embedded track',
            'language' => 'jpn',
            'kind' => 'embedded',
            'forced' => false,
            'hearing_impaired' => true,
        ])
        ->and($item['subtitle_tracks'][0]['fingerprint'])->toMatch('/^[a-f0-9]{64}$/');
});

test('a history row drops URL providers and unusable languages and keeps fixed fallbacks', function (): void {
    $subtitleItemMapper = resolve(SubtitleItemMapper::class);

    expect($subtitleItemMapper->historyItem([
        'sonarrEpisodeId' => 701,
        'seriesTitle' => 'Frieren',
        'language' => 'eng',
        'provider' => 'https://provider.example',
        'action' => '1',
    ], 'episode'))->toBe([
        'media_type' => 'episode',
        'media_id' => 701,
        'title' => 'Frieren — Episode',
        'language' => 'eng',
        'provider' => null,
        'action' => null,
        'score' => '',
        'occurred_at' => '',
    ])
        ->and($subtitleItemMapper->historyItem(['radarrId' => 801, 'language' => ['code3' => '']], 'movie'))->toBeNull()
        ->and($subtitleItemMapper->historyItem(['radarrId' => 0, 'language' => 'eng'], 'movie'))->toBeNull();
});

test('current subtitle languages normalise the language of every track-shaped entry', function (): void {
    $subtitleItemMapper = resolve(SubtitleItemMapper::class);

    expect($subtitleItemMapper->currentSubtitleLanguages([['language' => 'eng'], ['language' => 'swe'], 'junk']))->toBe(['eng', 'swe'])
        ->and($subtitleItemMapper->currentSubtitleLanguages('not-a-list'))->toBe([]);
});
