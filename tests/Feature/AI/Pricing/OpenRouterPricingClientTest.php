<?php

declare(strict_types=1);

use App\Services\AiUsage\Pricing\OpenRouterPricingClient;
use App\Services\AiUsage\Pricing\PricingTransportException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    Sleep::fake();
    config()->set('mediamanager.ai.pricing.openrouter.retries', 0);
});

function openRouterClientFailure(): ?PricingTransportException
{
    try {
        resolve(OpenRouterPricingClient::class)->fetch();
    } catch (PricingTransportException $pricingTransportException) {
        return $pricingTransportException;
    }

    return null;
}

test('fetch returns the data list without sending credentials', function (): void {
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response((string) file_get_contents(base_path('tests/Fixtures/OpenRouter/models.json'))),
    ]);

    $models = resolve(OpenRouterPricingClient::class)->fetch();

    expect($models)->toBeList()
        ->and($models[0]['id'])->toBe('anthropic/claude-opus-5.5');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/models'
        && ! $request->hasHeader('Authorization')
        && str_contains((string) $request->header('User-Agent')[0], 'OpenRouterPricingClient'));
});

test('a response without a data list is an invalid shape', function (array $body): void {
    Http::fake(['openrouter.ai/*' => Http::response($body)]);

    expect(openRouterClientFailure()?->category)->toBe(PricingTransportException::CATEGORY_INVALID_SHAPE);
})->with([
    'no data key' => [['models' => []]],
    'data is an object' => [['data' => ['a' => 1]]],
]);

test('server errors surface as classified openrouter failures', function (): void {
    Http::fake(['openrouter.ai/*' => Http::response('down', 502)]);

    $failure = openRouterClientFailure();

    expect($failure?->category)->toBe(PricingTransportException::CATEGORY_SERVER_ERROR)
        ->and($failure?->getMessage())->toBe('OpenRouter pricing API responded with HTTP 502.');
});
