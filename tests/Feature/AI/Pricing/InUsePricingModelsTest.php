<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Models\AiTaskModel;
use App\Models\AiUsageRecord;
use App\Services\AiUsage\Pricing\InUsePricingModels;
use App\Settings\AiSettings;
use Laravel\Ai\Ai;

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
    config()->set('mediamanager.ai.sub_agent_model', '');
    config()->set('mediamanager.ai.pricing.updater_model', '');
    config()->set('mediamanager.decision_agent.model', '');
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-chat')->create();
    AiTaskModel::factory()->task(AiTask::Title)->selecting('openai', 'gpt-title')->create();
    AiTaskModel::factory()->task(AiTask::FileInspector)->selecting('openai', 'gpt-sub-agent')->create();
    AiTaskModel::factory()->task(AiTask::PriceUpdater)->selecting('openai', 'gpt-updater')->create();
    AiTaskModel::factory()->task(AiTask::Decision)->selecting('anthropic', 'claude-decision')->create();
    // Keep the default embeddings selection (also openai) out of this
    // provider's bucket so it doesn't confound the assertion below.
    resolve(AiSettings::class)->setEmbeddingsProvider('openrouter');

    $inUsePricingModels = resolve(InUsePricingModels::class);

    expect($inUsePricingModels->forProvider('openai'))->toBe(['gpt-chat', 'gpt-title', 'gpt-sub-agent', 'gpt-updater'])
        ->and($inUsePricingModels->forProvider('anthropic'))->toBe(['claude-decision']);
});

test('per-event decision overrides and the failover model count as in use', function (): void {
    AiTaskModel::factory()->event('sonarr:Download')->selecting('anthropic', 'claude-event-override')->create();
    AiTaskModel::factory()->event('radarr:Grab')->state(['model' => 'gpt-event-default-provider'])->create();
    AiTaskModel::factory()->task(AiTask::Failover)->selecting('anthropic', 'claude-failover')->create();

    $inUsePricingModels = resolve(InUsePricingModels::class);

    expect($inUsePricingModels->contains('anthropic', 'claude-event-override'))->toBeTrue()
        ->and($inUsePricingModels->contains('openai', 'gpt-event-default-provider'))->toBeTrue()
        ->and($inUsePricingModels->contains('anthropic', 'claude-failover'))->toBeTrue();
});

test('a failover without a model adds nothing to the in-use list', function (): void {
    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => 'anthropic'])->create();

    expect(resolve(InUsePricingModels::class)->forProvider('anthropic'))->toBe([]);
});

test('a blank embeddings model counts the provider default embeddings model as in use', function (): void {
    resolve(AiSettings::class)->setEmbeddingsProvider('openrouter');

    expect(resolve(InUsePricingModels::class)->contains('openrouter', 'google/gemini-embedding-001'))->toBeTrue();
});

test('the auto title model counts its resolved cheapest model, never the sentinel', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-chat')->create();
    AiTaskModel::factory()->task(AiTask::Title)->selecting('openai', AiSettings::AUTO_MODEL)->create();

    $inUsePricingModels = resolve(InUsePricingModels::class);

    expect($inUsePricingModels->contains('openai', AiSettings::AUTO_MODEL))->toBeFalse()
        ->and($inUsePricingModels->contains('openai', Ai::textProvider('openai')->cheapestTextModel()))->toBeTrue();
});
