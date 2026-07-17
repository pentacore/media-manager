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

test('allows the post-migration provider hosts', function (string $url): void {
    Http::fake([
        '*' => Http::response('<html><body>pricing table</body></html>', 200),
    ]);

    $result = json_decode(
        (new WebFetchTool)->handle(new Request(['url' => $url])),
        true,
    );

    expect($result)->not->toHaveKey('error')
        ->and($result['status'])->toBe(200);
})->with([
    'https://developers.openai.com/api/docs/pricing',
    'https://claude.com/pricing',
    'https://platform.claude.com/docs/en/about-claude/pricing',
    'https://openrouter.ai/api/v1/models',
]);

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

test('follows an on-allowlist redirect and returns the target content', function (): void {
    Http::fake([
        'openai.com/pricing' => Http::response('', 301, ['Location' => 'https://platform.openai.com/docs/pricing']),
        'platform.openai.com/docs/pricing' => Http::response('<html><body>gpt-5-mini pricing</body></html>', 200),
    ]);

    $result = json_decode(
        (new WebFetchTool)->handle(new Request(['url' => 'https://openai.com/pricing'])),
        true,
    );

    expect($result)->not->toHaveKey('error')
        ->and($result['status'])->toBe(200)
        ->and($result['url'])->toBe('https://platform.openai.com/docs/pricing')
        ->and($result['content'])->toContain('gpt-5-mini');
});

test('refuses a redirect that leaves the allowlist without requesting the target', function (): void {
    Http::fake([
        'openai.com/pricing' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
    ]);

    $result = json_decode(
        (new WebFetchTool)->handle(new Request(['url' => 'https://openai.com/pricing'])),
        true,
    );

    expect($result['error'])->toBe('redirected_off_allowlist');
    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), '169.254.169.254'));
});

test('refuses a redirect to an explicit port even on an allowlisted host', function (): void {
    Http::fake([
        'openai.com/pricing' => Http::response('', 302, ['Location' => 'https://openai.com:6379/x']),
    ]);

    $result = json_decode(
        (new WebFetchTool)->handle(new Request(['url' => 'https://openai.com/pricing'])),
        true,
    );

    expect($result['error'])->toBe('redirected_off_allowlist');
});

test('gives up after the redirect budget is exhausted', function (): void {
    Http::fake([
        'openai.com/*' => Http::response('', 301, ['Location' => 'https://openai.com/pricing']),
    ]);

    $result = json_decode(
        (new WebFetchTool)->handle(new Request(['url' => 'https://openai.com/pricing'])),
        true,
    );

    expect($result['error'])->toBe('too_many_redirects');
});

test('risk is Read', function (): void {
    expect((new WebFetchTool)->risk())->toBe(Risk::Read);
});
