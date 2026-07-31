<?php

declare(strict_types=1);

use App\Support\UpstreamErrorText;

test('it strips paths and query strings from an upstream message', function (): void {
    $sanitized = UpstreamErrorText::sanitize(
        'cURL error 7: connect to bazarr.test for http://bazarr.test/api/providers?apikey=secret while writing /mnt/private/anime/Frieren.srt',
    );

    expect($sanitized)->not->toContain('apikey=secret')
        ->and($sanitized)->not->toContain('/mnt/private')
        ->and($sanitized)->not->toContain('Frieren.srt')
        ->and($sanitized)->toContain('cURL error 7');
});

test('it describes an empty upstream message instead of storing nothing', function (): void {
    expect(UpstreamErrorText::sanitize('   '))
        ->toBe('The upstream service returned an error without a usable description.');
});

test('it bounds the sanitized message to the requested limit', function (): void {
    expect(UpstreamErrorText::sanitize(str_repeat('error ', 200), 40))
        ->toHaveLength(40);
});
