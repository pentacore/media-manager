<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\PriceFetcher\WebFetchTool;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('rejects URLs not on the pricing-pages allowlist', function (): void {
    $result = json_decode(
        (new WebFetchTool)->handle(new Request(['url' => 'https://evil.example.com/api'])),
        true,
    );

    expect($result['error'])->toBe('host_not_allowed');
});

test('fetches an allowlisted URL and returns plain-text content', function (): void {
    Http::fake([
        'openai.com/api/pricing' => Http::response(
            '<html><head><style>.a{color:red}</style><script>x()</script></head>'
            ."<body>  <h1>Pricing</h1>\n<p>gpt-5-mini · $0.10/1M input</p></body></html>",
            200,
        ),
    ]);

    $result = json_decode(
        (new WebFetchTool)->handle(new Request(['url' => 'https://openai.com/api/pricing'])),
        true,
    );

    expect($result['status'])->toBe(200)
        ->and($result['content'])->toContain('gpt-5-mini')
        ->and($result['content'])->not->toContain('<script>')
        ->and($result['content'])->not->toContain('<style>');
});

test('reports upstream non-2xx as a structured error', function (): void {
    Http::fake([
        'openai.com/*' => Http::response('forbidden', 403),
    ]);

    $result = json_decode(
        (new WebFetchTool)->handle(new Request(['url' => 'https://openai.com/api/pricing'])),
        true,
    );

    expect($result['error'])->toBe('fetch_failed')
        ->and($result['status'])->toBe(403);
});

test('risk is Read', function (): void {
    expect((new WebFetchTool)->risk())->toBe(Risk::Read);
});
