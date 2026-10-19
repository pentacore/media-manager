<?php

declare(strict_types=1);

use App\Enums\SecondOpinionEngine;
use App\Models\User;
use App\Settings\DecisionAgentSettings;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
});

test('guests cannot access decision agent settings', function (): void {
    $this->get(route('admin.decision-agent.index'))
        ->assertRedirect(route('login'));
});

test('non-admin cannot access decision agent settings', function (): void {
    $user = User::factory()->member()->create();

    $this->actingAs($user)
        ->get(route('admin.decision-agent.index'))
        ->assertForbidden();
});

test('admin sees current settings on index', function (): void {
    $admin = User::factory()->admin()->create();
    $decisionAgentSettings = resolve(DecisionAgentSettings::class);
    $decisionAgentSettings->setEnabled(true);
    $decisionAgentSettings->setEventAllowlist(['sonarr:ManualInteractionRequired']);

    $this->actingAs($admin)
        ->get(route('admin.decision-agent.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/DecisionAgent/Index')
            ->where('settings.enabled', true)
            ->where('settings.event_allowlist', ['sonarr:ManualInteractionRequired'])
            ->missing('models')
            ->missing('reasoningLevels')
            ->missing('settings.model')
            ->missing('settings.reasoning_level')
            ->has('eventCatalog.sonarr')
        );
});

test('admin can update settings', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.decision-agent.update'), [
            'enabled' => true,
            'event_allowlist' => ['sonarr:ManualInteractionRequired', 'radarr:ManualInteractionRequired'],
            'allow_manual_import' => true,
            'notify_on_suggest' => false,
            'notify_on_act' => true,
            'max_actions_per_run' => 5,
            'second_opinion_engine' => 'llm',
            'second_opinion_jev_threshold' => 0.8,
        ])
        ->assertRedirect(route('admin.decision-agent.index'));

    $decisionAgentSettings = resolve(DecisionAgentSettings::class);
    expect($decisionAgentSettings->enabled())->toBeTrue();
    expect($decisionAgentSettings->eventAllowlist())->toBe(['sonarr:ManualInteractionRequired', 'radarr:ManualInteractionRequired']);
    expect($decisionAgentSettings->allowManualImport())->toBeTrue();
    expect($decisionAgentSettings->notifyOnSuggest())->toBeFalse();
    expect($decisionAgentSettings->notifyOnAct())->toBeTrue();
    expect($decisionAgentSettings->maxActionsPerRun())->toBe(5);
});

test('update rejects an event key outside the catalog', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.decision-agent.update'), [
            'enabled' => true,
            'event_allowlist' => ['sonarr:NotARealEvent'],
            'allow_manual_import' => false,
            'notify_on_suggest' => true,
            'notify_on_act' => true,
            'max_actions_per_run' => 3,
            'second_opinion_engine' => 'llm',
            'second_opinion_jev_threshold' => 0.8,
        ])
        ->assertSessionHasErrors('event_allowlist.0');
});

test('update clamps and validates max actions per run', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.decision-agent.update'), [
            'enabled' => true,
            'event_allowlist' => [],
            'allow_manual_import' => false,
            'notify_on_suggest' => true,
            'notify_on_act' => true,
            'max_actions_per_run' => 0,
            'second_opinion_engine' => 'llm',
            'second_opinion_jev_threshold' => 0.8,
        ])
        ->assertSessionHasErrors('max_actions_per_run');
});

test('update allows an empty allowlist', function (): void {
    $admin = User::factory()->admin()->create();
    resolve(DecisionAgentSettings::class)->setEventAllowlist(['sonarr:ManualInteractionRequired']);

    $this->actingAs($admin)
        ->put(route('admin.decision-agent.update'), [
            'enabled' => false,
            'event_allowlist' => [],
            'allow_manual_import' => false,
            'notify_on_suggest' => true,
            'notify_on_act' => true,
            'max_actions_per_run' => 3,
            'second_opinion_engine' => 'llm',
            'second_opinion_jev_threshold' => 0.8,
        ])
        ->assertRedirect(route('admin.decision-agent.index'));

    expect(resolve(DecisionAgentSettings::class)->eventAllowlist())->toBe([]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function decisionAgentSettingsPayload(array $overrides = []): array
{
    return [
        'enabled' => true,
        'event_allowlist' => [],
        'allow_manual_import' => false,
        'notify_on_suggest' => true,
        'notify_on_act' => true,
        'max_actions_per_run' => 3,
        'second_opinion_engine' => 'llm',
        'second_opinion_jev_threshold' => 0.8,
        ...$overrides,
    ];
}

test('the decision agent page shows the second opinion settings', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.decision-agent.index'))
        ->assertInertia(fn ($page) => $page->component('Admin/DecisionAgent/Index')
            ->where('settings.second_opinion_engine', 'llm')
            ->where('settings.second_opinion_jev_threshold', 0.8)
            ->where('secondOpinionEngines.0', ['value' => 'llm', 'label' => 'LLM'])
            ->has('classifierConfigured'));
});

test('an admin saves the jev cascade with a threshold when a classifier is configured', function (): void {
    config(['mediamanager.ai.classification.provider' => 'openrouter', 'ai.providers.openrouter.key' => 'sk-test']);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.decision-agent.update'), decisionAgentSettingsPayload(['second_opinion_engine' => 'jev_then_llm', 'second_opinion_jev_threshold' => 0.7]))
        ->assertRedirect();

    expect(resolve(DecisionAgentSettings::class)->secondOpinionEngine())->toBe(SecondOpinionEngine::JevThenLlm)
        ->and(resolve(DecisionAgentSettings::class)->secondOpinionJevThreshold())->toBe(0.7);
});

test('jev engines need a configured classifier and the threshold stays within 0..1', function (array $overrides, string $field): void {
    config(['mediamanager.ai.classification.provider' => 'openrouter', 'ai.providers.openrouter.key' => '']);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.decision-agent.update'), decisionAgentSettingsPayload($overrides))
        ->assertSessionHasErrors($field);
})->with([
    'jev without classifier' => [['second_opinion_engine' => 'jev'], 'second_opinion_engine'],
    'threshold above one' => [['second_opinion_jev_threshold' => 1.5], 'second_opinion_jev_threshold'],
]);

test('a stored jev engine does not block saving other settings once the classifier is gone', function (): void {
    resolve(DecisionAgentSettings::class)->setSecondOpinionEngine(SecondOpinionEngine::Jev);
    config(['mediamanager.ai.classification.provider' => 'openrouter', 'ai.providers.openrouter.key' => '']);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.decision-agent.update'), decisionAgentSettingsPayload(['second_opinion_engine' => 'jev', 'max_actions_per_run' => 5]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(resolve(DecisionAgentSettings::class)->maxActionsPerRun())->toBe(5)
        ->and(resolve(DecisionAgentSettings::class)->secondOpinionEngine())->toBe(SecondOpinionEngine::Jev);
});

test('without a classifier only the llm or the stored engine can be saved', function (string $stored, string $submitted, bool $allowed): void {
    resolve(DecisionAgentSettings::class)->setSecondOpinionEngine(SecondOpinionEngine::from($stored));
    config(['mediamanager.ai.classification.provider' => 'openrouter', 'ai.providers.openrouter.key' => '']);

    $response = $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.decision-agent.update'), decisionAgentSettingsPayload(['second_opinion_engine' => $submitted]));

    $allowed ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('second_opinion_engine');
})->with([
    'llm to jev' => ['llm', 'jev', false],
    'jev to the cascade' => ['jev', 'jev_then_llm', false],
    'jev back to llm' => ['jev', 'llm', true],
    'cascade kept' => ['jev_then_llm', 'jev_then_llm', true],
]);
