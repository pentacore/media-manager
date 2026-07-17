<?php

declare(strict_types=1);

use App\Support\UrlQueryRedactor;

it('strips query strings from urls embedded in a message', function (): void {
    $message = 'cURL error 28: Operation timed out for http://sab.local:8080/api?output=json&apikey=secret123&mode=version';

    $redacted = UrlQueryRedactor::redact($message);

    expect($redacted)->not->toContain('secret123')
        ->and($redacted)->toContain('http://sab.local:8080/api?[redacted]')
        ->and($redacted)->toStartWith('cURL error 28');
});

it('redacts every url in the message independently', function (): void {
    $message = 'first https://a.example/api?apikey=one then http://b.example/path?token=two end';

    $redacted = UrlQueryRedactor::redact($message);

    expect($redacted)->not->toContain('one')
        ->and($redacted)->not->toContain('two')
        ->and($redacted)->toBe('first https://a.example/api?[redacted] then http://b.example/path?[redacted] end');
});

it('leaves messages without query strings unchanged', function (): void {
    $message = 'Connection refused for http://sonarr.local:8989/api/v3/system/status (port closed)';

    expect(UrlQueryRedactor::redact($message))->toBe($message);
});
