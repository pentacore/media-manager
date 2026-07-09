<?php

declare(strict_types=1);

/**
 * @return array{key: ?string, host: ?string, port: int, scheme: string}
 */
function reverbMetaConfig(string $html): array
{
    preg_match('/<meta name="reverb-config" content="([^"]*)"/', $html, $matches);

    expect($matches)->not->toBeEmpty('Missing <meta name="reverb-config"> in page head');

    return json_decode(html_entity_decode($matches[1]), true);
}

test('reverb client config meta tag uses public config values', function (): void {
    config([
        'reverb.public.host' => 'ws.example.com',
        'reverb.public.port' => 8443,
        'reverb.public.scheme' => 'https',
    ]);

    $response = $this->get(route('login'));

    $response->assertOk();

    $meta = reverbMetaConfig($response->getContent());

    expect($meta['host'])->toBe('ws.example.com')
        ->and($meta['port'])->toBe(8443)
        ->and($meta['scheme'])->toBe('https');
});

test('reverb client config meta tag falls back to app url host when no public host is configured', function (): void {
    config([
        'app.url' => 'https://media.example.com',
        'reverb.public.host' => null,
        'reverb.public.port' => null,
        'reverb.public.scheme' => null,
    ]);

    $response = $this->get(route('login'));

    $response->assertOk();

    $meta = reverbMetaConfig($response->getContent());

    expect($meta['host'])->toBe('media.example.com');
});
