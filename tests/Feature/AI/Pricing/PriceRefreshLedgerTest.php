<?php

declare(strict_types=1);

use App\Services\AiUsage\Pricing\PriceRefreshLedger;
use App\Services\AiUsage\Pricing\RefreshScope;

test('a provider state starts with every counter at zero', function (): void {
    $priceRefreshLedger = new PriceRefreshLedger(verifyMode: false);

    expect($priceRefreshLedger->providerState('openai', PriceRefreshLedger::PROVIDER_OK))->toBe([
        'status' => 'ok', 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'locked' => 0, 'rejected' => 0,
        'anomalous' => 0, 'tiered' => 0, 'discrepancies' => 0, 'create_disabled' => 0, 'consensus' => 0,
        'conflicts' => 0, 'rejections' => [],
    ]);
});

test('unverified targets are de-duplicated and capped with an overflow marker', function (): void {
    $priceRefreshLedger = new PriceRefreshLedger(verifyMode: false);
    $priceRefreshLedger->unverifiedTargets = [
        ...array_map(static fn (int $index): string => 'openai:m'.$index, range(1, 30)),
        'openai:m1',
    ];

    $capped = $priceRefreshLedger->cappedUnverifiedTargets();

    expect($capped)->toHaveCount(26)
        ->and($capped[25])->toBe('+5 more');
});

test('fallback targets flatten to provider and provider:model entries', function (): void {
    $priceRefreshLedger = new PriceRefreshLedger(verifyMode: false);
    $priceRefreshLedger->fallbackTargets = ['anthropic' => [], 'openai' => ['gpt-a', 'gpt-b']];

    expect($priceRefreshLedger->flattenFallbackTargets())->toBe(['anthropic', 'openai:gpt-a', 'openai:gpt-b'])
        ->and($priceRefreshLedger->agentScope())->toBeInstanceOf(RefreshScope::class);
});
