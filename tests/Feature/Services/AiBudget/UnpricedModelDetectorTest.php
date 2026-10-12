<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Services\AiBudget\UnpricedModelDetector;
use App\Settings\AiSettings;

beforeEach(function (): void {
    config()->set('ai.default', 'openai');
    config()->set('mediamanager.ai.model', 'chat-model');
    config()->set('mediamanager.ai.title_model', 'title-model');
    config()->set('mediamanager.ai.sub_agent_model', 'sub-agent-model');
    config()->set('mediamanager.ai.pricing.updater_model', '');
    config()->set('mediamanager.decision_agent.model', 'decision-model');
});

function priceModels(string ...$models): void
{
    foreach ($models as $model) {
        AiModelPrice::factory()->create(['provider' => 'openai', 'model' => $model]);
    }
}

test('nothing is reported without a hard cap', function (): void {
    expect(resolve(UnpricedModelDetector::class)->forHardCap())->toBe([]);
});

test('nothing is reported when every selected model is priced', function (): void {
    resolve(AiSettings::class)->setHardBudgetUsd(25.0);
    priceModels('chat-model', 'title-model', 'sub-agent-model', 'decision-model');

    expect(resolve(UnpricedModelDetector::class)->forHardCap())->toBe([]);
});

test('each unpriced selected model is reported with its role', function (): void {
    resolve(AiSettings::class)->setHardBudgetUsd(25.0);
    priceModels('chat-model', 'title-model');

    expect(resolve(UnpricedModelDetector::class)->forHardCap())->toBe([
        ['role' => 'Decision agent', 'provider' => 'openai', 'model' => 'decision-model'],
        ['role' => 'File inspector', 'provider' => 'openai', 'model' => 'sub-agent-model'],
        ['role' => 'Stuck download investigator', 'provider' => 'openai', 'model' => 'sub-agent-model'],
    ]);
});

test('a model shared by several roles is reported for each role', function (): void {
    config()->set('mediamanager.decision_agent.model', '');
    config()->set('mediamanager.ai.sub_agent_model', '');

    resolve(AiSettings::class)->setHardBudgetUsd(25.0);
    priceModels('title-model');

    expect(array_column(resolve(UnpricedModelDetector::class)->forHardCap(), 'role'))
        ->toBe(['Chat', 'Decision agent', 'File inspector', 'Stuck download investigator', 'Price updater', 'Benchmark judge']);
});

test('each selected model is looked up under its own provider', function (): void {
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
    resolve(AiSettings::class)->setHardBudgetUsd(25.0);
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openrouter', 'anthropic/claude-sonnet-5')->create();
    priceModels('title-model', 'sub-agent-model', 'decision-model');
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'anthropic/claude-sonnet-5']);

    expect(resolve(UnpricedModelDetector::class)->forHardCap())->toContain(
        ['role' => 'Chat', 'provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-5'],
    );
});

test('a decision event override with its own model is reported under its event', function (): void {
    resolve(AiSettings::class)->setHardBudgetUsd(25.0);
    priceModels('chat-model', 'title-model', 'sub-agent-model', 'decision-model');
    AiTaskModel::factory()->event('radarr:Grab')->selecting('openai', 'event-model')->create();

    expect(resolve(UnpricedModelDetector::class)->forHardCap())->toBe([
        ['role' => 'Decision agent (radarr:Grab)', 'provider' => 'openai', 'model' => 'event-model'],
    ]);
});

test('unpriced lower tiers are reported with their tier number, live picks keep their role', function (): void {
    resolve(AiSettings::class)->setHardBudgetUsd(25.0);
    priceModels('chat-model', 'title-model', 'sub-agent-model', 'decision-model', 'chat-one');
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'chat-one')->position(0)->create();
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'chat-two')->position(1)->create();
    AiTaskModel::factory()->event('some_event')->selecting('openai', 'event-one')->position(0)->create();
    AiTaskModel::factory()->event('some_event')->selecting('openai', 'event-two')->position(1)->create();

    $unpriced = resolve(UnpricedModelDetector::class)->forHardCap();

    expect($unpriced)->toContain(
        ['role' => 'Chat (tier 2)', 'provider' => 'openai', 'model' => 'chat-two'],
        ['role' => 'Decision agent (some_event)', 'provider' => 'openai', 'model' => 'event-one'],
        ['role' => 'Decision agent (some_event, tier 2)', 'provider' => 'openai', 'model' => 'event-two'],
    )->and(array_column($unpriced, 'role'))->not->toContain('Chat (tier 1)', 'Decision agent (some_event, tier 1)');
});

test('a tier equal to the live pick of its role is not listed twice', function (): void {
    resolve(AiSettings::class)->setHardBudgetUsd(25.0);
    priceModels('title-model', 'sub-agent-model', 'decision-model');
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'chat-one')->position(0)->create();
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'chat-one')->position(1)->create();

    expect(array_column(resolve(UnpricedModelDetector::class)->forHardCap(), 'role'))
        ->toContain('Chat')->not->toContain('Chat (tier 2)');
});
