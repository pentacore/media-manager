<?php

declare(strict_types=1);

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\WebPushConfig;
use App\Services\Notifications\WebPushResult;

beforeEach(function (): void {
    config()->set('inertia.testing.ensure_pages_exist', false);
    app()->instance(WebPushConfig::class, new WebPushConfig('test-vapid-public-key', 'private', 'mailto:a@example.test', ['push.example.test']));
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

test('store accepts an allowed push service and rejects everything else', function (string $endpoint, bool $allowed): void {
    app()->instance(WebPushConfig::class, new WebPushConfig('k', 'p', null, ['fcm.googleapis.com', '*.push.services.mozilla.com']));
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('settings.push-subscriptions.store'), pushBody($endpoint));

    if ($allowed) {
        $response->assertSessionHasNoErrors();
        expect(PushSubscription::query()->count())->toBe(1);
    } else {
        $response->assertSessionHasErrors('endpoint');
        expect(PushSubscription::query()->count())->toBe(0);
    }
})->with([
    'fcm' => ['https://fcm.googleapis.com/fcm/send/abc', true],
    'wildcard subdomain' => ['https://updates.push.services.mozilla.com/wpush/v2/abc', true],
    'bare wildcard suffix' => ['https://push.services.mozilla.com/x', false],
    'suffix lookalike' => ['https://evilpush.services.mozilla.com/x', false],
    'metadata ip' => ['https://169.254.169.254/x', false],
    'localhost' => ['https://localhost/x', false],
    'attacker lookalike' => ['https://evilfcm.googleapis.com.attacker.test/x', false],
    'custom port' => ['https://fcm.googleapis.com:8443/x', false],
    'userinfo' => ['https://user@fcm.googleapis.com/x', false],
]);

test('the allowed hosts come from config', function (): void {
    config(['services.webpush.allowed_hosts' => ['push.corp.test']]);
    $config = WebPushConfig::fromConfig();

    expect($config->allowsEndpoint('https://push.corp.test/a'))->toBeTrue()
        ->and($config->allowsEndpoint('https://fcm.googleapis.com/a'))->toBeFalse();
});

test('test flashes the not configured error when web push is unavailable', function (): void {
    app()->instance(WebPushConfig::class, new WebPushConfig(null, null, null));
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('settings.push-subscriptions.test'))->assertSessionHasErrors('test_push');
});

test('guests are redirected', function (): void {
    $row = PushSubscription::factory()->create();
    $this->delete(route('settings.push-subscriptions.destroy', $row))->assertRedirect(route('login'));

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
            ->where('webPush.devices.0.endpoint_hash', hash('sha256', $mine->endpoint))
            ->has('webPush.devices.0.created_at')
            ->where('webPush.devices.0.last_used_at', null)
            ->where('channels', fn ($channels): bool => in_array('webpush', $channels->all(), true)));
});
