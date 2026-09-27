<?php

declare(strict_types=1);

use App\Services\AiUsage\Pricing\PricingTransportException;
use App\Services\AiUsage\Pricing\XaiPricingClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    Sleep::fake();
    config()->set('mediamanager.ai.pricing.xai.retries', 0);
});

function xaiClientFailure(): ?PricingTransportException
{
    try {
        resolve(XaiPricingClient::class)->fetch();
    } catch (PricingTransportException $pricingTransportException) {
        return $pricingTransportException;
    }

    return null;
}

test('fetch sends the xai key and returns the models list', function (): void {
    config()->set('ai.providers.xai.key', 'xai-test-key');

    Http::fake([
        'api.x.ai/v1/language-models' => Http::response((string) file_get_contents(base_path('tests/Fixtures/Xai/language-models.json'))),
    ]);

    $models = resolve(XaiPricingClient::class)->fetch();

    expect($models)->toBeList()
        ->and($models[0]['id'])->toBe('grok-5');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer xai-test-key'));
});

test('a missing key fails as not configured without any request', function (): void {
    config()->set('ai.providers.xai.key');
    Http::fake();

    expect(xaiClientFailure()?->category)->toBe(PricingTransportException::CATEGORY_NOT_CONFIGURED);

    Http::assertNothingSent();
});

test('a rejected key is a client error', function (): void {
    config()->set('ai.providers.xai.key', 'bad-key');
    Http::fake(['api.x.ai/*' => Http::response(['error' => 'unauthorized'], 401)]);

    expect(xaiClientFailure()?->category)->toBe(PricingTransportException::CATEGORY_CLIENT_ERROR);
});

test('a response without a models list is an invalid shape', function (): void {
    config()->set('ai.providers.xai.key', 'xai-test-key');
    Http::fake(['api.x.ai/*' => Http::response(['data' => []])]);

    expect(xaiClientFailure()?->category)->toBe(PricingTransportException::CATEGORY_INVALID_SHAPE);
});
