<?php

declare(strict_types=1);

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
    $settings = resolve(DecisionAgentSettings::class);
    $settings->setEnabled(true);
    $settings->setEventAllowlist(['sonarr:ManualInteractionRequired']);

    $this->actingAs($admin)
        ->get(route('admin.decision-agent.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/DecisionAgent/Index')
            ->where('settings.enabled', true)
            ->where('settings.event_allowlist', ['sonarr:ManualInteractionRequired'])
            ->has('models')
            ->has('eventCatalog.sonarr')
        );
});

test('admin can update settings', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.decision-agent.update'), [
            'enabled' => true,
            'model' => 'gpt-5-mini',
            'event_allowlist' => ['sonarr:ManualInteractionRequired', 'radarr:ManualInteractionRequired'],
            'allow_manual_import' => true,
            'notify_on_suggest' => false,
            'notify_on_act' => true,
            'max_actions_per_run' => 5,
        ])
        ->assertRedirect(route('admin.decision-agent.index'));

    $settings = resolve(DecisionAgentSettings::class);
    expect($settings->enabled())->toBeTrue();
    expect($settings->model())->toBe('gpt-5-mini');
    expect($settings->eventAllowlist())->toBe(['sonarr:ManualInteractionRequired', 'radarr:ManualInteractionRequired']);
    expect($settings->allowManualImport())->toBeTrue();
    expect($settings->notifyOnSuggest())->toBeFalse();
    expect($settings->notifyOnAct())->toBeTrue();
    expect($settings->maxActionsPerRun())->toBe(5);
});

test('update rejects an event key outside the catalog', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.decision-agent.update'), [
            'enabled' => true,
            'model' => 'gpt-5-mini',
            'event_allowlist' => ['sonarr:NotARealEvent'],
            'allow_manual_import' => false,
            'notify_on_suggest' => true,
            'notify_on_act' => true,
            'max_actions_per_run' => 3,
        ])
        ->assertSessionHasErrors('event_allowlist.0');
});

test('update clamps and validates max actions per run', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.decision-agent.update'), [
            'enabled' => true,
            'model' => 'gpt-5-mini',
            'event_allowlist' => [],
            'allow_manual_import' => false,
            'notify_on_suggest' => true,
            'notify_on_act' => true,
            'max_actions_per_run' => 0,
        ])
        ->assertSessionHasErrors('max_actions_per_run');
});

test('update allows an empty allowlist', function (): void {
    $admin = User::factory()->admin()->create();
    resolve(DecisionAgentSettings::class)->setEventAllowlist(['sonarr:ManualInteractionRequired']);

    $this->actingAs($admin)
        ->put(route('admin.decision-agent.update'), [
            'enabled' => false,
            'model' => 'gpt-5-mini',
            'event_allowlist' => [],
            'allow_manual_import' => false,
            'notify_on_suggest' => true,
            'notify_on_act' => true,
            'max_actions_per_run' => 3,
        ])
        ->assertRedirect(route('admin.decision-agent.index'));

    expect(resolve(DecisionAgentSettings::class)->eventAllowlist())->toBe([]);
});
