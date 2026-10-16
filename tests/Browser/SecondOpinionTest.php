<?php

declare(strict_types=1);

use App\Enums\SecondOpinionEngine;
use App\Enums\SecondOpinionMode;
use App\Models\ActionTypeConfig;
use App\Models\User;
use App\Settings\DecisionAgentSettings;

test('an admin picks a second opinion on an approval rule', function (): void {
    $actionTypeConfig = ActionTypeConfig::factory()->create(['type' => 'monitor_series', 'label' => 'Monitor series']);
    ActionTypeConfig::factory()->create(['type' => 'grab_release', 'label' => 'Grab release']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.rules.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-rule-second-opinion="grab_release"]', '—')
        ->click('[data-rule-second-opinion="monitor_series"] [data-second-opinion-option="hold"]')
        ->assertAttribute('[data-rule-second-opinion="monitor_series"] [data-second-opinion-option="hold"]', 'aria-pressed', 'true')
        ->assertSee('Rule updated.');

    expect($actionTypeConfig->fresh()->second_opinion)->toBe(SecondOpinionMode::Hold);
});

test('jev engines are disabled until a classifier is configured', function (): void {
    config(['mediamanager.ai.classification.provider' => 'openrouter', 'ai.providers.openrouter.key' => '']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.decision-agent.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-second-opinion-classifier-hint]')
        ->click('[data-second-opinion-engine]')
        ->assertAttribute('[data-second-opinion-engine-option="jev"]', 'data-disabled', '');
});

test('an admin switches the second opinion to jev decides with a threshold', function (): void {
    config(['mediamanager.ai.classification.provider' => 'openrouter', 'ai.providers.openrouter.key' => 'sk-test']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.decision-agent.index', absolute: false))
        ->assertNoSmoke()
        ->assertMissing('[data-second-opinion-threshold]')
        ->click('[data-second-opinion-engine]')
        ->click('[data-second-opinion-engine-option="jev"]')
        ->fill('[data-second-opinion-threshold]', '0.65')
        ->press('Save settings')
        ->assertSee('Decision agent settings updated.');

    expect(resolve(DecisionAgentSettings::class)->secondOpinionEngine())->toBe(SecondOpinionEngine::Jev)
        ->and(resolve(DecisionAgentSettings::class)->secondOpinionJevThreshold())->toBe(0.65);
});
