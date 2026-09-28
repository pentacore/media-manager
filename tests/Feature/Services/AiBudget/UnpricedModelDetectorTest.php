<?php

declare(strict_types=1);

use App\Models\AiModelPrice;
use App\Services\AiBudget\UnpricedModelDetector;
use App\Settings\AiSettings;
use App\Settings\DecisionAgentSettings;

beforeEach(function (): void {
    config()->set('ai.default', 'openai');
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setModel('chat-model');
    $aiSettings->setTitleModel('title-model');
    $aiSettings->setSubAgentModel('sub-agent-model');

    resolve(DecisionAgentSettings::class)->setModel('decision-model');
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
        ['role' => 'Sub-agents', 'provider' => 'openai', 'model' => 'sub-agent-model'],
    ]);
});

test('a model shared by several roles is reported for each role', function (): void {
    config()->set('mediamanager.decision_agent.model', '');
    config()->set('mediamanager.ai.sub_agent_model', '');

    resolve(AiSettings::class)->setHardBudgetUsd(25.0);
    resolve(AiSettings::class)->setSubAgentModel(null);
    resolve(DecisionAgentSettings::class)->setModel('');
    priceModels('title-model');

    expect(array_column(resolve(UnpricedModelDetector::class)->forHardCap(), 'role'))
        ->toBe(['Chat', 'Decision agent', 'Sub-agents']);
});
