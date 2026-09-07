<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\AiBudgetSoftLimitReached;
use App\Notifications\MediaReplacementStatusChanged;
use App\Notifications\ServiceUpdateAvailable;
use App\Notifications\ServiceWarning;
use App\Services\Notifications\PreferenceResolver;
use Illuminate\Http\Client\ConnectionException;
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
            ->where('channels', PreferenceResolver::CHANNELS)
            ->has('catalog')
            ->has('catalog.0.severities.warning.database')
            ->where('catalog.0.severities.warning.database', true)
            ->where('catalog.0.severities.warning.broadcast', true)
            ->where('catalog.0.severities.warning.mail', false)
            ->where('catalog.0.severities.warning.ntfy', false)
            ->where('catalog.0.severities.warning.discord', false)
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
                        'error' => ['database' => true, 'broadcast' => true, 'mail' => true, 'ntfy' => true, 'discord' => true, 'telegram' => true, 'webhook' => true],
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

    $errorRow = NotificationPreference::where('user_id', $user->id)
        ->where('notification_class', ServiceWarning::class)
        ->where('severity', 'error')
        ->first();

    expect($errorRow)->not->toBeNull();
    expect($errorRow->discord)->toBeTrue();
    expect($errorRow->telegram)->toBeTrue();
    expect($errorRow->webhook)->toBeTrue();
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
    Http::preventStrayRequests();
    Http::fake(['ntfy.example.com*' => Http::response(['id' => '1'])]);

    $user = User::factory()->create(['ntfy_topic' => 'mm-alerts']);

    $this->actingAs($user)
        ->post(route('settings.notifications.test'), ['channel' => 'ntfy'])
        ->assertRedirect();

    Http::assertSent(fn ($request): bool => $request['topic'] === 'mm-alerts');
});

test('test notification endpoint errors without a topic', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $user = User::factory()->create(['ntfy_topic' => null]);

    $this->actingAs($user)
        ->post(route('settings.notifications.test'), ['channel' => 'ntfy'])
        ->assertSessionHasErrors('test_channel');

    Http::assertNothingSent();
});

test('edit exposes masked destinations and which channels are configured', function (): void {
    config()->set('services.ntfy.server', 'https://ntfy.example.com');
    config()->set('services.telegram.token', '123:abc');
    $user = User::factory()->create([
        'ntfy_topic' => 'mm',
        'discord_webhook_url' => 'https://discord.com/api/webhooks/1/abcd9999',
        'telegram_chat_id' => '-1001',
        'webhook_url' => 'https://hooks.example.com/mm',
        'webhook_secret' => 's3cret',
    ]);

    $this->actingAs($user)
        ->get(route('settings.notifications.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('destinations.ntfy_topic', 'mm')
            ->where('destinations.discord_webhook_url_hint', '…9999')
            ->where('destinations.telegram_chat_id', '-1001')
            ->where('destinations.webhook_url', 'https://hooks.example.com/mm')
            ->where('destinations.webhook_secret_set', true)
            ->where('channelsConfigured.ntfy', true)
            ->where('channelsConfigured.telegram', true)
            ->missing('destinations.discord_webhook_url')
            ->missing('destinations.webhook_secret')
        );
});

test('update stores the push destinations', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.notifications.update'), [
            'preferences' => [],
            'discord_webhook_url' => 'https://discord.com/api/webhooks/1/abc',
            'telegram_chat_id' => '-1001',
            'webhook_url' => 'https://hooks.example.com/mm',
            'webhook_secret' => 's3cret',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->discord_webhook_url)->toBe('https://discord.com/api/webhooks/1/abc')
        ->and($user->telegram_chat_id)->toBe('-1001')
        ->and($user->webhook_url)->toBe('https://hooks.example.com/mm')
        ->and($user->webhook_secret)->toBe('s3cret');
});

test('omitting a secret keeps it while an empty string clears it', function (): void {
    $user = User::factory()->create(['discord_webhook_url' => 'https://discord.com/api/webhooks/1/abc', 'webhook_secret' => 's3cret']);

    $this->actingAs($user)->put(route('settings.notifications.update'), ['preferences' => []])->assertRedirect();
    expect($user->fresh()->discord_webhook_url)->toBe('https://discord.com/api/webhooks/1/abc')
        ->and($user->fresh()->webhook_secret)->toBe('s3cret');

    $this->actingAs($user)->put(route('settings.notifications.update'), ['preferences' => [], 'discord_webhook_url' => '', 'webhook_secret' => ''])->assertRedirect();
    expect($user->fresh()->discord_webhook_url)->toBeNull()
        ->and($user->fresh()->webhook_secret)->toBeNull();
});

test('update rejects a non-discord webhook url and a non-numeric chat id', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.notifications.update'), [
            'preferences' => [],
            'discord_webhook_url' => 'https://example.com/not-discord',
            'telegram_chat_id' => 'abc',
        ])
        ->assertSessionHasErrors(['discord_webhook_url', 'telegram_chat_id']);
});

test('test endpoint delivers through the requested channel', function (): void {
    Http::preventStrayRequests();
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    $user = User::factory()->create(['discord_webhook_url' => 'https://discord.com/api/webhooks/1/abc']);

    $this->actingAs($user)
        ->post(route('settings.notifications.test'), ['channel' => 'discord'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://discord.com/api/webhooks/1/abc')
        && $request['embeds'][0]['title'] === 'MediaManager test notification');
});

test('test endpoint errors when the channel has no destination or delivery fails', function (): void {
    Http::preventStrayRequests();
    Http::fake(['hooks.example.com/*' => Http::response('nope', 500)]);
    $user = User::factory()->create(['webhook_url' => 'https://hooks.example.com/mm']);

    $this->actingAs($user)->post(route('settings.notifications.test'), ['channel' => 'telegram'])->assertSessionHasErrors('test_channel');
    $this->actingAs($user)->post(route('settings.notifications.test'), ['channel' => 'webhook'])->assertSessionHasErrors('test_channel');
    $this->actingAs($user)->post(route('settings.notifications.test'), ['channel' => 'pager'])->assertSessionHasErrors('channel');
});

test('a failed test send never leaks the telegram bot token to the user', function (): void {
    config()->set('services.telegram.token', '123:abc');
    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/*' => fn () => throw new ConnectionException('cURL error 6: https://api.telegram.org/bot123:abc/sendMessage'),
    ]);
    $user = User::factory()->create(['telegram_chat_id' => '-1001']);

    $this->actingAs($user)
        ->post(route('settings.notifications.test'), ['channel' => 'telegram'])
        ->assertSessionHasErrors('test_channel');

    expect(session('errors')->first('test_channel'))->toBe('Telegram delivery failed: Could not reach the provider.');
});
