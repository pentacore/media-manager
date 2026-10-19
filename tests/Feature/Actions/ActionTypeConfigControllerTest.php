<?php

declare(strict_types=1);

use App\Enums\SecondOpinionMode;
use App\Models\ActionTypeConfig;
use App\Models\User;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
});

test('guests are redirected to login', function (): void {
    $this->get(route('actions.rules.index'))->assertRedirect(route('login'));
});

test('members cannot access rules index', function (): void {
    $member = User::factory()->member()->create();
    $this->actingAs($member)->get(route('actions.rules.index'))->assertForbidden();
});

test('admins can list rules', function (): void {
    $admin = User::factory()->admin()->create();
    ActionTypeConfig::factory()->count(4)->create();

    $this->actingAs($admin)
        ->get(route('actions.rules.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Actions/Rules')
            ->has('rules', 4)
        );
});

test('admins can update a rule', function (): void {
    $admin = User::factory()->admin()->create();
    $config = ActionTypeConfig::factory()->create([
        'requires_approval' => true,
        'is_enabled' => true,
    ]);

    $this->actingAs($admin)
        ->from(route('actions.rules.index'))
        ->patch(route('actions.rules.update', $config), [
            'requires_approval' => false,
            'is_enabled' => false,
            'second_opinion' => 'off',
        ])
        ->assertRedirect(route('actions.rules.index'));

    $config->refresh();
    expect($config->requires_approval)->toBeFalse();
    expect($config->is_enabled)->toBeFalse();
});

test('update validates required fields', function (): void {
    $admin = User::factory()->admin()->create();
    $config = ActionTypeConfig::factory()->create();

    $this->actingAs($admin)
        ->patch(route('actions.rules.update', $config), [])
        ->assertSessionHasErrors(['requires_approval', 'is_enabled', 'second_opinion']);
});

test('members cannot update rules', function (): void {
    $member = User::factory()->member()->create();
    $config = ActionTypeConfig::factory()->create();

    $this->actingAs($member)
        ->patch(route('actions.rules.update', $config), [
            'requires_approval' => false,
            'is_enabled' => true,
            'second_opinion' => 'off',
        ])
        ->assertForbidden();
});

test('an admin sets the second opinion of a supported rule', function (): void {
    $actionTypeConfig = ActionTypeConfig::factory()->create(['type' => 'monitor_series']);

    $this->actingAs(User::factory()->admin()->create())
        ->patch(route('actions.rules.update', $actionTypeConfig), ['requires_approval' => false, 'is_enabled' => true, 'second_opinion' => 'reject'])
        ->assertRedirect();

    expect($actionTypeConfig->fresh()->second_opinion)->toBe(SecondOpinionMode::Reject);
});

test('a rule no automated proposer produces only takes off', function (): void {
    $actionTypeConfig = ActionTypeConfig::factory()->create(['type' => 'grab_release']);

    $this->actingAs(User::factory()->admin()->create())
        ->patch(route('actions.rules.update', $actionTypeConfig), ['requires_approval' => false, 'is_enabled' => true, 'second_opinion' => 'hold'])
        ->assertSessionHasErrors('second_opinion');
});

test('the rules page says which rules support a second opinion', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'monitor_series']);
    ActionTypeConfig::factory()->create(['type' => 'grab_release']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('actions.rules.index'))
        ->assertInertia(fn ($page) => $page->component('Actions/Rules')
            ->where('rules', function ($rules): bool {
                $byType = collect($rules)->keyBy('type');

                return $byType['grab_release']['second_opinion_supported'] === false
                    && $byType['monitor_series']['second_opinion_supported'] === true
                    && $byType['monitor_series']['second_opinion'] === 'off';
            }));
});
