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

test('it keeps the scheme and host of a URL so the failing server stays visible', function (): void {
    expect(UpstreamErrorText::sanitize('cURL error 7: Failed to connect to sonarr.local port 8989 for http://sonarr.local:8989/api/v3/system/status?apikey=secret'))
        ->toContain('http://sonarr.local:8989')
        ->not->toContain('secret')
        ->not->toContain('http:/[redacted path]');
});

test('it redacts Windows drive-letter and UNC paths', function (): void {
    expect(UpstreamErrorText::sanitize('Import failed for C:\Downloads\Show\file.mkv and \\\\nas\media\tv\Show'))
        ->toBe('Import failed for [redacted path] and [redacted path]');
});
