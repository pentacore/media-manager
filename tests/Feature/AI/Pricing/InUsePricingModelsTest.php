<?php

declare(strict_types=1);

use App\Models\AiUsageRecord;
use App\Services\AiUsage\Pricing\InUsePricingModels;
use App\Settings\AiSettings;

beforeEach(function (): void {
    config()->set('ai.default', 'openai');

    foreach (['openrouter', 'typesafe', 'cohere', 'jina'] as $provider) {
        config()->set(sprintf('ai.providers.%s.key', $provider));
    }
});

test('models recorded in ai usage count as in use under their canonical provider', function (): void {
    AiUsageRecord::factory()->create(['provider' => 'openrouter', 'model' => 'vendor/used']);
    AiUsageRecord::factory()->create(['provider' => 'google', 'model' => 'gemini-used']);
    AiUsageRecord::factory()->create(['provider' => null, 'model' => 'orphan']);

    $inUsePricingModels = resolve(InUsePricingModels::class);

    expect($inUsePricingModels->contains('openrouter', 'vendor/used'))->toBeTrue()
        ->and($inUsePricingModels->contains('gemini', 'gemini-used'))->toBeTrue()
        ->and($inUsePricingModels->contains('openrouter', 'vendor/unused'))->toBeFalse()
        ->and($inUsePricingModels->forProvider('openrouter'))->toBe(['vendor/used']);
});

test('the configured classification and reranking models count as in use when their provider has a key', function (): void {
    config()->set('ai.providers.openrouter.key', 'openrouter-test-key');
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setClassificationProvider('openrouter');
    $aiSettings->setClassificationModel('vendor/classifier');
    $aiSettings->setRerankingProvider('openrouter');
    $aiSettings->setRerankingModel('vendor/reranker');

    $inUsePricingModels = resolve(InUsePricingModels::class);

    expect($inUsePricingModels->forProvider('openrouter'))->toBe(['vendor/classifier', 'vendor/reranker']);
});

test('an unset classification model counts the provider default model as in use', function (): void {
    config()->set('ai.providers.openrouter.key', 'openrouter-test-key');
    config()->set('ai.providers.openrouter.models.classification.default', 'vendor/default-classifier');

    resolve(AiSettings::class)->setClassificationProvider('openrouter');

    expect(resolve(InUsePricingModels::class)->contains('openrouter', 'vendor/default-classifier'))->toBeTrue();
});

test('classification and reranking models are not in use when their provider has no key', function (): void {
    config()->set('ai.providers.openrouter.key');
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setClassificationProvider('openrouter');
    $aiSettings->setClassificationModel('vendor/classifier');

    expect(resolve(InUsePricingModels::class)->forProvider('openrouter'))->toBe([]);
});

test('the configured agent models count as in use under their own selection provider', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setModel('gpt-chat');
    $aiSettings->setTitleModel('gpt-title');
    $aiSettings->setSubAgentModel('gpt-sub-agent');
    $aiSettings->setPriceUpdaterModel('gpt-updater');

    expect(resolve(InUsePricingModels::class)->forProvider('openai'))
        ->toBe(['gpt-title', 'gpt-chat', 'gpt-sub-agent', 'gpt-updater']);
});

test('the auto title model sentinel is never counted as a model name', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setModel('gpt-chat');
    $aiSettings->setTitleModel(AiSettings::AUTO_MODEL);

    expect(resolve(InUsePricingModels::class)->contains('openai', AiSettings::AUTO_MODEL))->toBeFalse();
});
