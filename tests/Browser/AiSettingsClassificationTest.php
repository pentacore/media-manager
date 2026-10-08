<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Models\AiTaskModel;
use App\Models\User;
use App\Settings\AiSettings;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

test('admin can enable the decision gate from AI settings', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-settings.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-classification-settings]')
        ->assertVisible('[data-reranking-settings]')
        ->assertVisible('[data-advanced-tools]')
        ->assertSeeIn('[data-decision-gate-toggle]', 'Disabled')
        ->click('[data-decision-gate-toggle]')
        ->assertSeeIn('[data-decision-gate-toggle]', 'Enabled')
        ->click('Save settings')
        ->assertSee('AI settings updated.')
        ->assertSeeIn('[data-decision-gate-toggle]', 'Enabled');

    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->decisionGateEnabled())->toBeTrue()
        ->and($aiSettings->subtitleTriageEnabled())->toBeFalse()
        ->and($aiSettings->chatRoutingEnabled())->toBeFalse()
        ->and($aiSettings->decisionGateThreshold())->toBe(0.3)
        ->and(AiTaskModel::query()->exists())->toBeFalse();
});

test('the AI settings page flags a classification provider without an API key', function (): void {
    config()->set('ai.providers.openrouter.key');
    config()->set('ai.providers.cohere.key', 'cohere-test-key');
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-settings.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-classification-settings]', 'No API key configured')
        ->assertMissing('[data-reranking-key-missing]');
});

test('the AI settings page shows which advanced tools the provider chain allows', function (): void {
    config()->set('ai.default', 'openai');
    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => 'gemini'])->create();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-settings.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-advanced-tool="tool_search"]', 'inactive')
        ->assertSeeIn('[data-advanced-tool="code_execution"]', 'active')
        ->assertDontSeeIn('[data-advanced-tool="code_execution"]', 'inactive');
});

test('admin can switch the reranking provider and model', function (): void {
    config()->set('ai.providers.jina.key', 'jina-test-key');
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-settings.index', absolute: false))
        ->assertNoSmoke()
        ->click('#reranking_provider')
        ->click('[role="option"][aria-label="Jina"]')
        ->assertMissing('[data-reranking-key-missing]')
        ->type('#reranking_model', 'jina-reranker-v3')
        ->click('Save settings')
        ->assertSee('AI settings updated.');

    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->rerankingProvider())->toBe('jina')
        ->and($aiSettings->rerankingModel())->toBe('jina-reranker-v3');
});
