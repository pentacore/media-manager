<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\AiBudgetSoftLimitReached;
use App\Notifications\MediaReplacementStatusChanged;
use App\Notifications\ServiceUpdateAvailable;
use App\Notifications\ServiceWarning;
use Illuminate\Support\Facades\Http;

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

test('catalog includes service update available with mail defaulting on', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('settings.notifications.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('catalog.2.class', ServiceUpdateAvailable::class)
            ->where('catalog.2.severities.info.mail', true)
        );
});

test('catalog includes the subtitle replacement status notification', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('settings.notifications.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('catalog.3.class', MediaReplacementStatusChanged::class)
            ->where('catalog.3.severities.warning.database', true)
        );
});

test('update persists the ntfy topic and clears it when null', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.notifications.update'), [
            'preferences' => [],
            'ntfy_topic' => 'mm-alerts_1',
        ])
        ->assertRedirect();

    expect($user->refresh()->ntfy_topic)->toBe('mm-alerts_1');

    $this->actingAs($user)
        ->put(route('settings.notifications.update'), [
            'preferences' => [],
            'ntfy_topic' => null,
        ])
        ->assertRedirect();

    expect($user->refresh()->ntfy_topic)->toBeNull();
});

test('update rejects topics with url-breaking characters', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.notifications.update'), [
            'preferences' => [],
            'ntfy_topic' => 'bad topic/../x',
        ])
        ->assertSessionHasErrors('ntfy_topic');
});

test('test notification endpoint pushes to the user topic', function (): void {
    config()->set('services.ntfy.server', 'https://ntfy.example.com');
    Http::fake(['ntfy.example.com/*' => Http::response(['id' => '1'])]);

    $user = User::factory()->create(['ntfy_topic' => 'mm-alerts']);

    $this->actingAs($user)
        ->post(route('settings.notifications.test'))
        ->assertRedirect();

    Http::assertSent(fn ($request): bool => $request['topic'] === 'mm-alerts');
});

test('test notification endpoint errors without a topic', function (): void {
    Http::fake();
    $user = User::factory()->create(['ntfy_topic' => null]);

    $this->actingAs($user)
        ->post(route('settings.notifications.test'))
        ->assertSessionHasErrors('ntfy_topic');

    Http::assertNothingSent();
});
