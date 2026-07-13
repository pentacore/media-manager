<?php

declare(strict_types=1);

use App\Services\MediaReplacement\LanguageNormalizer;

test('it normalizes language names and codes to canonical codes', function (?string $language, ?string $expected): void {
    expect((new LanguageNormalizer)->normalize($language))->toBe($expected);
})->with([
    'English name' => ['English', 'eng'],
    'English two-letter code' => ['en', 'eng'],
    'English canonical code' => ['ENG', 'eng'],
    'Swedish name' => ['Swedish', 'swe'],
    'Swedish two-letter code' => ['sv', 'swe'],
    'Japanese name' => ['Japanese', 'jpn'],
    'Japanese two-letter code' => ['ja', 'jpn'],
    'Danish name' => ['Danish', 'dan'],
    'Danish two-letter code' => ['da', 'dan'],
    'Norwegian name' => ['Norwegian', 'nor'],
    'Norwegian two-letter code' => ['no', 'nor'],
    'Finnish name' => ['Finnish', 'fin'],
    'Finnish two-letter code' => ['fi', 'fin'],
    'empty string' => ['', null],
    'unknown language' => [' Klingon ', 'klingon'],
]);

test('it normalizes unique languages while preserving their first occurrence order', function (): void {
    expect((new LanguageNormalizer)->normalizeMany([
        ' Swedish ',
        '',
        'English',
        'sv',
        'JAPANESE',
        null,
    ]))->toBe(['swe', 'eng', 'jpn']);
});

test('it preserves unknown numeric-looking language codes as strings', function (): void {
    expect((new LanguageNormalizer)->normalizeMany(['123']))->toBe(['123']);
});
