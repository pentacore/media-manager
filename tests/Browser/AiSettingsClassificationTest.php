<?php

declare(strict_types=1);

use App\Models\AiModelPrice;
use App\Models\User;
use App\Settings\AiSettings;
use Laravel\Ai\Enums\Lab;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    // The model select is fed by the pricing catalog; the form is invalid
    // without a row for the configured chat model.
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => resolve(AiSettings::class)->model()]);
});

test('admin can enable the decision gate from AI settings', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-settings.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-classification-settings]')
        ->assertVisible('[data-reranking-settings]')
        ->assertVisible('[data-sub-agent-model]')
        ->assertVisible('[data-advanced-tools]')
        ->assertSeeIn('[data-decision-gate-toggle]', 'Disabled')
        ->click('[data-decision-gate-toggle]')
        ->assertSeeIn('[data-decision-gate-toggle]', 'Enabled')
        ->click('Save settings')
        ->assertSee('AI settings updated.')
        ->assertSeeIn('[data-decision-gate-toggle]', 'Enabled');

    $settings = resolve(AiSettings::class);

    expect($settings->decisionGateEnabled())->toBeTrue()
        ->and($settings->subtitleTriageEnabled())->toBeFalse()
        ->and($settings->chatRoutingEnabled())->toBeFalse()
        ->and($settings->decisionGateThreshold())->toBe(0.3)
        ->and($settings->rawSubAgentModel())->toBeNull();
});

test('the AI settings page flags a classification provider without an API key', function (): void {
    config()->set('ai.providers.openrouter.key', null);
    config()->set('ai.providers.cohere.key', 'cohere-test-key');
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-settings.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-classification-settings]', 'No API key configured')
        ->assertMissing('[data-reranking-key-missing]');
});

test('the AI settings page shows which advanced tools the provider chain allows', function (): void {
    config()->set('ai.default', 'openai');
    resolve(AiSettings::class)->setFailoverProvider(Lab::Gemini);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-settings.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-advanced-tool="tool_search"]', 'inactive')
        ->assertSeeIn('[data-advanced-tool="code_execution"]', 'active')
        ->assertDontSeeIn('[data-advanced-tool="code_execution"]', 'inactive');
});
