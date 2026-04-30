<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\AiBudgetSoftLimitReached;
use App\Notifications\ServiceWarning;

beforeEach(function (): void {
    config()->set('inertia.testing.ensure_pages_exist', false);
});

test('GET /settings/notifications returns the default catalog', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('settings.notifications.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Notifications')
            ->has('catalog')
            ->has('catalog.0.severities.warning.database')
            ->where('catalog.0.severities.warning.database', true)
            ->where('catalog.0.severities.warning.broadcast', true)
            ->where('catalog.0.severities.warning.mail', false)
            ->where('catalog.0.severities.warning.ntfy', false)
        );
});

test('PUT /settings/notifications persists overrides', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.notifications.update'), [
            'preferences' => [
                [
                    'class' => ServiceWarning::class,
                    'severities' => [
                        'warning' => ['database' => true, 'broadcast' => false, 'mail' => false, 'ntfy' => true],
                        'error' => ['database' => true, 'broadcast' => true, 'mail' => true, 'ntfy' => true],
                    ],
                ],
            ],
        ])
        ->assertRedirect();

    $row = NotificationPreference::where('user_id', $user->id)
        ->where('notification_class', ServiceWarning::class)
        ->where('severity', 'warning')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->database)->toBeTrue();
    expect($row->broadcast)->toBeFalse();
    expect($row->ntfy)->toBeTrue();
});

test('PUT silently drops unknown notification classes', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.notifications.update'), [
            'preferences' => [
                [
                    'class' => 'App\\Notifications\\NopeNotARealClass',
                    'severities' => [
                        'warning' => ['database' => true, 'broadcast' => true, 'mail' => false, 'ntfy' => false],
                    ],
                ],
            ],
        ])
        ->assertRedirect();

    expect(NotificationPreference::count())->toBe(0);
});

test('catalog entries cover both ServiceWarning and AiBudgetSoftLimitReached', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('settings.notifications.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('catalog.0.class', ServiceWarning::class)
            ->where('catalog.1.class', AiBudgetSoftLimitReached::class)
        );
});
