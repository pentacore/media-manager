<?php

declare(strict_types=1);

use App\Services\AiUsage\Pricing\LiteLlmPricingClient;
use App\Services\AiUsage\Pricing\PricingTransportException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    Sleep::fake();
    config()->set('mediamanager.ai.pricing.litellm.retries', 0);
});

test('fetch returns the model map', function (): void {
    Http::fake([
        'raw.githubusercontent.com/*' => Http::response((string) file_get_contents(base_path('tests/Fixtures/LiteLlm/prices.json'))),
    ]);

    $map = resolve(LiteLlmPricingClient::class)->fetch();

    expect($map)->toHaveKey('claude-opus-5-5');
});

test('a list payload is an invalid shape', function (): void {
    Http::fake(['raw.githubusercontent.com/*' => Http::response([['a' => 1]])]);

    try {
        resolve(LiteLlmPricingClient::class)->fetch();
        $caught = null;
    } catch (PricingTransportException $pricingTransportException) {
        $caught = $pricingTransportException;
    }

    expect($caught?->category)->toBe(PricingTransportException::CATEGORY_INVALID_SHAPE)
        ->and($caught?->getMessage())->toBe('LiteLLM pricing response was not a top-level model object.');
});
