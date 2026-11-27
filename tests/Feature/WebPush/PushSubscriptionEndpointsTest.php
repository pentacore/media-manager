<?php

declare(strict_types=1);

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\WebPushConfig;
use App\Services\Notifications\WebPushResult;

beforeEach(function (): void {
    config()->set('inertia.testing.ensure_pages_exist', false);
    app()->instance(WebPushConfig::class, new WebPushConfig('test-vapid-public-key', 'private', 'mailto:a@example.test'));
});

/**
 * @return array<string, mixed>
 */
function pushBody(string $endpoint = 'https://push.example.test/send/abc'): array
{
    return [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => 'BPublicKey', 'auth' => 'AuthSecret'],
        'contentEncoding' => 'aes128gcm',
    ];
}

test('store creates a subscription for the caller', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36')
        ->post(route('settings.push-subscriptions.store'), pushBody())
        ->assertRedirect();

    $row = PushSubscription::query()->sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->endpoint)->toBe('https://push.example.test/send/abc')
        ->and($row->public_key)->toBe('BPublicKey')
        ->and($row->auth_token)->toBe('AuthSecret')
        ->and($row->content_encoding)->toBe('aes128gcm')
        ->and($row->vapid_public_key)->toBe('test-vapid-public-key')
        ->and($row->label)->toBe('Chrome on Android');
});

test('store moves an endpoint that belonged to another user', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    PushSubscription::factory()->for($first)->create(['endpoint' => 'https://push.example.test/send/abc']);

    $this->actingAs($second)->post(route('settings.push-subscriptions.store'), pushBody())->assertRedirect();

    $row = PushSubscription::query()->sole();
    expect($row->user_id)->toBe($second->id);
});

test('store rejects invalid bodies', function (array $override, string $field): void {
    $user = User::factory()->create();
    $body = array_replace_recursive(pushBody(), $override);
    if (($override['remove'] ?? null) !== null) {
        unset($body['keys']['auth'], $body['remove']);
    }

    $this->actingAs($user)->post(route('settings.push-subscriptions.store'), $body)->assertSessionHasErrors($field);

    expect(PushSubscription::query()->count())->toBe(0);
})->with([
    'missing auth' => [['remove' => true], 'keys.auth'],
    'http endpoint' => [['endpoint' => 'http://push.example.test/send/abc'], 'endpoint'],
    'bad encoding' => [['contentEncoding' => 'nope'], 'contentEncoding'],
]);

test('store is unprocessable when web push is unavailable', function (): void {
    app()->instance(WebPushConfig::class, new WebPushConfig(null, null, null));
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('settings.push-subscriptions.store'), pushBody())->assertUnprocessable();

    expect(PushSubscription::query()->count())->toBe(0);
});

test('destroy deletes the callers own device', function (): void {
    $user = User::factory()->create();
    $row = PushSubscription::factory()->for($user)->create();

    $this->actingAs($user)->delete(route('settings.push-subscriptions.destroy', $row))->assertRedirect();

    expect(PushSubscription::query()->find($row->id))->toBeNull();
});

test('destroy refuses another users device', function (): void {
    $user = User::factory()->create();
    $row = PushSubscription::factory()->create();

    $this->actingAs($user)->delete(route('settings.push-subscriptions.destroy', $row))->assertForbidden();

    expect(PushSubscription::query()->find($row->id))->not->toBeNull();
});

test('test sends through the sender and flashes success', function (): void {
    $user = User::factory()->create();
    PushSubscription::factory()->for($user)->create();
    $fake = fakeWebPushSender();

    $this->actingAs($user)->post(route('settings.push-subscriptions.test'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($fake->sent)->toHaveCount(1)
        ->and(json_decode($fake->sent[0]['payload'], true)['url'])->toBe(route('settings.notifications.edit'));
});

test('test reports a failing delivery', function (): void {
    $user = User::factory()->create();
    $row = PushSubscription::factory()->for($user)->create();
    fakeWebPushSender([$row->endpoint => new WebPushResult(false, false, 'HTTP 503')]);

    $this->actingAs($user)->post(route('settings.push-subscriptions.test'))
        ->assertSessionHasErrors('test_push');
});

test('test errors when the user has no devices', function (): void {
    $user = User::factory()->create();
    $fake = fakeWebPushSender();

    $this->actingAs($user)->post(route('settings.push-subscriptions.test'))
        ->assertSessionHasErrors('test_push');

    expect($fake->sent)->toBe([]);
});

test('test is rate limited to five a minute', function (): void {
    $user = User::factory()->create();
    PushSubscription::factory()->for($user)->create();
    fakeWebPushSender();

    foreach (range(1, 5) as $ignored) {
        $this->actingAs($user)->post(route('settings.push-subscriptions.test'))->assertRedirect();
    }

    $this->actingAs($user)->post(route('settings.push-subscriptions.test'))->assertStatus(429);
});

test('guests are redirected', function (): void {
    $this->post(route('settings.push-subscriptions.store'), pushBody())->assertRedirect(route('login'));
    $this->post(route('settings.push-subscriptions.test'))->assertRedirect(route('login'));
});

test('the settings page lists only the users own devices', function (): void {
    $user = User::factory()->create();
    $mine = PushSubscription::factory()->for($user)->create(['label' => 'Chrome on Android']);
    PushSubscription::factory()->create();

    $this->actingAs($user)->get(route('settings.notifications.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('channelsConfigured.webpush', true)
            ->where('webPush.available', true)
            ->where('webPush.publicKey', 'test-vapid-public-key')
            ->has('webPush.devices', 1)
            ->where('webPush.devices.0.id', $mine->id)
            ->where('webPush.devices.0.label', 'Chrome on Android')
            ->has('webPush.devices.0.created_at')
            ->where('webPush.devices.0.last_used_at', null)
            ->where('channels', fn ($channels): bool => in_array('webpush', $channels->all(), true)));
});
