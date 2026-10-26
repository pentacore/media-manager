<?php

declare(strict_types=1);

use App\Enums\SecondOpinionEngine;
use App\Enums\SecondOpinionMode;
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

/**
 * @param  array<string, mixed>  $fields
 * @return array{event_key: string, mode: string|null, engine: string|null, jev_threshold: float|int|null}
 */
function decisionAgentEventOverride(string $eventKey, array $fields = []): array
{
    return ['event_key' => $eventKey, 'mode' => null, 'engine' => null, 'jev_threshold' => null, ...$fields];
}

test('the page lists stored per-event overrides and the second opinion modes', function (): void {
    resolve(DecisionAgentSettings::class)->setSecondOpinionEventOverrides([
        'sonarr:Download' => ['mode' => SecondOpinionMode::Reject, 'engine' => null, 'jev_threshold' => 0.6],
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.decision-agent.index'))
        ->assertInertia(fn ($page) => $page->component('Admin/DecisionAgent/Index')
            ->where('settings.second_opinion_event_overrides', [decisionAgentEventOverride('sonarr:Download', ['mode' => 'reject', 'jev_threshold' => 0.6])])
            ->where('secondOpinionModes.0', ['value' => 'off', 'label' => 'Off'])
            ->where('secondOpinionModes.2', ['value' => 'reject', 'label' => 'Reject if it disagrees']));
});

test('an admin saves per-event overrides and an all-inherit entry is dropped', function (): void {
    config(['mediamanager.ai.classification.provider' => 'openrouter', 'ai.providers.openrouter.key' => 'sk-test']);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.decision-agent.update'), decisionAgentSettingsPayload(['second_opinion_event_overrides' => [
            decisionAgentEventOverride('sonarr:Download', ['mode' => 'hold', 'engine' => 'jev', 'jev_threshold' => 0.55]),
            decisionAgentEventOverride('radarr:Grab'),
        ]]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.decision-agent.index'));

    expect(resolve(DecisionAgentSettings::class)->secondOpinionEventOverrides())->toBe([
        'sonarr:Download' => ['mode' => SecondOpinionMode::Hold, 'engine' => SecondOpinionEngine::Jev, 'jev_threshold' => 0.55],
    ]);
});

test('a save without the overrides field leaves stored overrides alone', function (): void {
    resolve(DecisionAgentSettings::class)->setSecondOpinionEventOverrides(['sonarr:Download' => ['mode' => SecondOpinionMode::Off, 'engine' => null, 'jev_threshold' => null]]);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.decision-agent.update'), decisionAgentSettingsPayload())
        ->assertSessionHasNoErrors();

    expect(resolve(DecisionAgentSettings::class)->secondOpinionModeOverride('sonarr:Download'))->toBe(SecondOpinionMode::Off);
});

test('per-event overrides are validated', function (array $fields, string $field): void {
    config(['mediamanager.ai.classification.provider' => 'openrouter', 'ai.providers.openrouter.key' => '']);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.decision-agent.update'), decisionAgentSettingsPayload(['second_opinion_event_overrides' => [
            decisionAgentEventOverride('sonarr:Download', $fields),
        ]]))
        ->assertSessionHasErrors($field);
})->with([
    'unknown event' => [['event_key' => 'sonarr:NotAnEvent', 'mode' => 'hold'], 'second_opinion_event_overrides.0.event_key'],
    'unknown mode' => [['mode' => 'sometimes'], 'second_opinion_event_overrides.0.mode'],
    'jev without a classifier' => [['engine' => 'jev'], 'second_opinion_event_overrides.0.engine'],
    'threshold above one' => [['jev_threshold' => 1.2], 'second_opinion_event_overrides.0.jev_threshold'],
    'threshold below zero' => [['jev_threshold' => -0.1], 'second_opinion_event_overrides.0.jev_threshold'],
]);

test('an event cannot be overridden twice', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.decision-agent.update'), decisionAgentSettingsPayload(['second_opinion_event_overrides' => [
            decisionAgentEventOverride('sonarr:Download', ['mode' => 'hold']),
            decisionAgentEventOverride('sonarr:Download', ['mode' => 'off']),
        ]]))
        ->assertSessionHasErrors(['second_opinion_event_overrides.0.event_key', 'second_opinion_event_overrides.1.event_key']);
});

test('a jev engine stored for an event survives a save once the classifier is gone, but another event cannot switch to it', function (): void {
    resolve(DecisionAgentSettings::class)->setSecondOpinionEventOverrides(['sonarr:Download' => ['mode' => null, 'engine' => SecondOpinionEngine::Jev, 'jev_threshold' => null]]);
    config(['mediamanager.ai.classification.provider' => 'openrouter', 'ai.providers.openrouter.key' => '']);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.decision-agent.update'), decisionAgentSettingsPayload(['max_actions_per_run' => 5, 'second_opinion_event_overrides' => [
            decisionAgentEventOverride('sonarr:Download', ['engine' => 'jev']),
        ]]))
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->put(route('admin.decision-agent.update'), decisionAgentSettingsPayload(['second_opinion_event_overrides' => [
            decisionAgentEventOverride('sonarr:Download', ['engine' => 'jev']),
            decisionAgentEventOverride('radarr:Grab', ['engine' => 'jev']),
        ]]))
        ->assertSessionHasErrors('second_opinion_event_overrides.1.engine')
        ->assertSessionDoesntHaveErrors('second_opinion_event_overrides.0.engine');

    expect(resolve(DecisionAgentSettings::class)->maxActionsPerRun())->toBe(5);
});

test('an override on an event outside the allowlist is accepted and kept', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.decision-agent.update'), decisionAgentSettingsPayload(['event_allowlist' => [], 'second_opinion_event_overrides' => [
            decisionAgentEventOverride('radarr:Grab', ['mode' => 'off']),
        ]]))
        ->assertSessionHasNoErrors();

    expect(resolve(DecisionAgentSettings::class)->secondOpinionModeOverride('radarr:Grab'))->toBe(SecondOpinionMode::Off);
});

test('a cleared threshold inherits the global one while zero is kept', function (): void {
    config(['mediamanager.ai.classification.provider' => 'openrouter', 'ai.providers.openrouter.key' => 'sk-test']);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.decision-agent.update'), decisionAgentSettingsPayload(['second_opinion_jev_threshold' => 0.8, 'second_opinion_event_overrides' => [
            decisionAgentEventOverride('sonarr:Download', ['engine' => 'jev', 'jev_threshold' => null]),
            decisionAgentEventOverride('radarr:Grab', ['engine' => 'jev', 'jev_threshold' => 0]),
        ]]))
        ->assertSessionHasNoErrors();

    $decisionAgentSettings = resolve(DecisionAgentSettings::class);
    expect($decisionAgentSettings->secondOpinionJevThreshold('sonarr:Download'))->toBe(0.8)
        ->and($decisionAgentSettings->secondOpinionJevThreshold('radarr:Grab'))->toBe(0.0);
});
