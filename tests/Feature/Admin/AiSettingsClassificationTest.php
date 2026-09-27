<?php

declare(strict_types=1);

use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Enums\Lab;

beforeEach(function (): void {
    Cache::flush();
});

/**
 * A valid AI settings update payload with the given overrides applied.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validAiSettingsPayload(array $overrides = []): array
{
    $aiSettings = resolve(AiSettings::class);

    return [
        'mode' => $aiSettings->mode()->value,
        'model' => $aiSettings->model(),
        'title_model' => $aiSettings->rawTitleModel(),
        'advisor_reasoning_level' => $aiSettings->advisorReasoningLevel(),
        'auto_create_pricing_providers' => [''],
        ...$overrides,
    ];
}

test('admin saves classification, reranking and sub-agent settings', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), validAiSettingsPayload([
            'classification_provider' => 'typesafe',
            'classification_model' => 'ts-classify-1',
            'decision_gate_enabled' => '1',
            'decision_gate_threshold' => '0.4',
            'subtitle_triage_enabled' => '0',
            'subtitle_triage_threshold' => '0.25',
            'chat_routing_enabled' => '1',
            'reranking_provider' => 'jina',
            'reranking_model' => 'jina-reranker-v3',
            'sub_agent_model' => 'gpt-5.4-nano',
            'price_updater_model' => 'gpt-5.4-mini',
        ]))
        ->assertRedirect();

    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->priceUpdaterModel())->toBe('gpt-5.4-mini')
        ->and($aiSettings->rawPriceUpdaterModel())->toBe('gpt-5.4-mini');

    expect($aiSettings->classificationProvider())->toBe('typesafe')
        ->and($aiSettings->classificationModel())->toBe('ts-classify-1')
        ->and($aiSettings->decisionGateEnabled())->toBeTrue()
        ->and($aiSettings->decisionGateThreshold())->toBe(0.4)
        ->and($aiSettings->subtitleTriageEnabled())->toBeFalse()
        ->and($aiSettings->subtitleTriageThreshold())->toBe(0.25)
        ->and($aiSettings->chatRoutingEnabled())->toBeTrue()
        ->and($aiSettings->rerankingProvider())->toBe('jina')
        ->and($aiSettings->rerankingModel())->toBe('jina-reranker-v3')
        ->and($aiSettings->subAgentModel())->toBe('gpt-5.4-nano')
        ->and($aiSettings->rawSubAgentModel())->toBe('gpt-5.4-nano');
});

test('gates default to off and the sub-agent model follows the chat model', function (): void {
    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->decisionGateEnabled())->toBeFalse()
        ->and($aiSettings->decisionGateThreshold())->toBe(0.3)
        ->and($aiSettings->subtitleTriageEnabled())->toBeFalse()
        ->and($aiSettings->subtitleTriageThreshold())->toBe(0.3)
        ->and($aiSettings->chatRoutingEnabled())->toBeFalse()
        ->and($aiSettings->classificationProvider())->toBe('openrouter')
        ->and($aiSettings->classificationModel())->toBeNull()
        ->and($aiSettings->rerankingProvider())->toBe('cohere')
        ->and($aiSettings->rerankingModel())->toBeNull()
        ->and($aiSettings->rawSubAgentModel())->toBeNull()
        ->and($aiSettings->subAgentModel())->toBe($aiSettings->model());
});

test('blank model fields clear back to their defaults', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setClassificationModel('ts-classify-1');
    $aiSettings->setRerankingModel('jina-reranker-v3');
    $aiSettings->setSubAgentModel('gpt-5.4-nano');
    $aiSettings->setPriceUpdaterModel('gpt-5.4-mini');

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), validAiSettingsPayload([
            'classification_model' => '',
            'reranking_model' => '',
            'sub_agent_model' => '',
            'price_updater_model' => '',
        ]))
        ->assertRedirect();

    expect($aiSettings->rawPriceUpdaterModel())->toBeNull()
        ->and($aiSettings->priceUpdaterModel())->toBe($aiSettings->model())
        ->and($aiSettings->classificationModel())->toBeNull()
        ->and($aiSettings->rerankingModel())->toBeNull()
        ->and($aiSettings->rawSubAgentModel())->toBeNull()
        ->and($aiSettings->subAgentModel())->toBe($aiSettings->model());
});

test('omitted classification fields leave the saved settings untouched', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setDecisionGateEnabled(true);
    $aiSettings->setDecisionGateThreshold(0.6);
    $aiSettings->setRerankingProvider('jina');

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), validAiSettingsPayload())
        ->assertRedirect();

    expect($aiSettings->decisionGateEnabled())->toBeTrue()
        ->and($aiSettings->decisionGateThreshold())->toBe(0.6)
        ->and($aiSettings->rerankingProvider())->toBe('jina');
});

test('invalid classification input is rejected', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), validAiSettingsPayload([
            'classification_provider' => 'openai',
            'decision_gate_threshold' => '1.5',
            'subtitle_triage_threshold' => '-0.1',
            'reranking_provider' => 'voyageai',
        ]))
        ->assertSessionHasErrors(['classification_provider', 'decision_gate_threshold', 'subtitle_triage_threshold', 'reranking_provider']);
});

test('index exposes classification settings, provider keys and advanced tool availability', function (): void {
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.typesafe.key');
    config()->set('ai.providers.cohere.key', 'cohere-test-key');

    resolve(AiSettings::class)->setFailoverProvider(Lab::Gemini);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiSettings/Index')
            ->where('settings.classification_provider', 'openrouter')
            ->where('settings.classification_model', null)
            ->where('settings.decision_gate_enabled', false)
            ->where('settings.decision_gate_threshold', 0.3)
            ->where('settings.subtitle_triage_enabled', false)
            ->where('settings.subtitle_triage_threshold', 0.3)
            ->where('settings.chat_routing_enabled', false)
            ->where('settings.reranking_provider', 'cohere')
            ->where('settings.reranking_model', null)
            ->where('settings.sub_agent_model', null)
            ->where('settings.price_updater_model', null)
            ->has('classificationProviders', 2)
            ->has('rerankingProviders', 3)
            ->where('providerKeys.typesafe', false)
            ->where('providerKeys.cohere', true)
            ->where('advancedTools.tool_search', false)
            ->where('advancedTools.code_execution', true)
        );
});
