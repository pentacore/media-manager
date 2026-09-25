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
    $settings = resolve(AiSettings::class);

    return [
        'mode' => $settings->mode()->value,
        'model' => $settings->model(),
        'title_model' => $settings->rawTitleModel(),
        'advisor_reasoning_level' => $settings->advisorReasoningLevel(),
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
        ]))
        ->assertRedirect();

    $settings = resolve(AiSettings::class);

    expect($settings->classificationProvider())->toBe('typesafe')
        ->and($settings->classificationModel())->toBe('ts-classify-1')
        ->and($settings->decisionGateEnabled())->toBeTrue()
        ->and($settings->decisionGateThreshold())->toBe(0.4)
        ->and($settings->subtitleTriageEnabled())->toBeFalse()
        ->and($settings->subtitleTriageThreshold())->toBe(0.25)
        ->and($settings->chatRoutingEnabled())->toBeTrue()
        ->and($settings->rerankingProvider())->toBe('jina')
        ->and($settings->rerankingModel())->toBe('jina-reranker-v3')
        ->and($settings->subAgentModel())->toBe('gpt-5.4-nano')
        ->and($settings->rawSubAgentModel())->toBe('gpt-5.4-nano');
});

test('gates default to off and the sub-agent model follows the chat model', function (): void {
    $settings = resolve(AiSettings::class);

    expect($settings->decisionGateEnabled())->toBeFalse()
        ->and($settings->decisionGateThreshold())->toBe(0.3)
        ->and($settings->subtitleTriageEnabled())->toBeFalse()
        ->and($settings->subtitleTriageThreshold())->toBe(0.3)
        ->and($settings->chatRoutingEnabled())->toBeFalse()
        ->and($settings->classificationProvider())->toBe('openrouter')
        ->and($settings->classificationModel())->toBeNull()
        ->and($settings->rerankingProvider())->toBe('cohere')
        ->and($settings->rerankingModel())->toBeNull()
        ->and($settings->rawSubAgentModel())->toBeNull()
        ->and($settings->subAgentModel())->toBe($settings->model());
});

test('blank model fields clear back to their defaults', function (): void {
    $settings = resolve(AiSettings::class);
    $settings->setClassificationModel('ts-classify-1');
    $settings->setRerankingModel('jina-reranker-v3');
    $settings->setSubAgentModel('gpt-5.4-nano');

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), validAiSettingsPayload([
            'classification_model' => '',
            'reranking_model' => '',
            'sub_agent_model' => '',
        ]))
        ->assertRedirect();

    expect($settings->classificationModel())->toBeNull()
        ->and($settings->rerankingModel())->toBeNull()
        ->and($settings->rawSubAgentModel())->toBeNull()
        ->and($settings->subAgentModel())->toBe($settings->model());
});

test('omitted classification fields leave the saved settings untouched', function (): void {
    $settings = resolve(AiSettings::class);
    $settings->setDecisionGateEnabled(true);
    $settings->setDecisionGateThreshold(0.6);
    $settings->setRerankingProvider('jina');

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), validAiSettingsPayload())
        ->assertRedirect();

    expect($settings->decisionGateEnabled())->toBeTrue()
        ->and($settings->decisionGateThreshold())->toBe(0.6)
        ->and($settings->rerankingProvider())->toBe('jina');
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
    config()->set('ai.providers.typesafe.key', null);
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
            ->has('classificationProviders', 2)
            ->has('rerankingProviders', 3)
            ->where('providerKeys.typesafe', false)
            ->where('providerKeys.cohere', true)
            ->where('advancedTools.tool_search', false)
            ->where('advancedTools.code_execution', true)
        );
});
