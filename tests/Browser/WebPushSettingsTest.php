<?php

declare(strict_types=1);

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\WebPushConfig;

const WEB_PUSH_TEST_ENDPOINT = 'https://fcm.googleapis.com/fcm/send/test-device';

/**
 * Headless Chromium has no push service, so the browser calls the
 * composable makes are replaced with a fake registration whose subscription
 * reports a fixed FCM-looking endpoint.
 */
function fakePushBrowserScript(): string
{
    $endpoint = WEB_PUSH_TEST_ENDPOINT;

    return <<<JS
        (() => {
            const subscription = {
                endpoint: '{$endpoint}',
                options: { applicationServerKey: null },
                toJSON: () => ({ endpoint: '{$endpoint}', keys: { p256dh: 'BFakePublicKey', auth: 'FakeAuth' } }),
                unsubscribe: async () => true,
            };
            let current = null;
            const registration = {
                pushManager: {
                    getSubscription: async () => current,
                    subscribe: async () => (current = subscription),
                },
            };
            Object.defineProperty(navigator.serviceWorker, 'register', { value: async () => registration, configurable: true });
            Object.defineProperty(navigator.serviceWorker, 'getRegistration', { value: async () => registration, configurable: true });
            Object.defineProperty(navigator.serviceWorker, 'ready', { value: Promise.resolve(registration), configurable: true });
            let permission = 'default';
            Notification.requestPermission = async () => (permission = 'granted');
            Object.defineProperty(Notification, 'permission', { get: () => permission, configurable: true });
            window.dispatchEvent(new Event('focus'));
        })()
    JS;
}

function configureWebPush(bool $available = true): void
{
    app()->instance(WebPushConfig::class, $available
        ? new WebPushConfig('BFakeVapidPublicKey', 'private', 'mailto:ops@example.test')
        : new WebPushConfig(null, null, null));
}

test('enabling push on this device registers it and lists it', function (): void {
    configureWebPush();
    $user = User::factory()->create();
    $this->actingAs($user);

    $webpage = visit(route('settings.notifications.edit', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertPresent('[data-push-card]')
        ->assertMissing('[data-push-device]');
    $webpage->script(fakePushBrowserScript());
    $webpage->click('[data-push-enable]')
        ->assertSee('This device will now receive web push notifications.')
        ->assertCount('[data-push-device]', 1)
        ->assertSeeIn('[data-push-device]', 'This device')
        ->assertPresent('[data-push-disable]')
        ->assertPresent('[data-push-test]');

    $row = PushSubscription::query()->sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->endpoint)->toBe(WEB_PUSH_TEST_ENDPOINT)
        ->and($row->vapid_public_key)->toBe('BFakeVapidPublicKey');
});

test('the card says web push is not configured when the keys are missing', function (): void {
    configureWebPush(false);
    $this->actingAs(User::factory()->create());

    visit(route('settings.notifications.edit', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-push-card]', 'Web push is not configured on this server.')
        ->assertMissing('[data-push-enable]');
});

test('removing another device deletes it', function (): void {
    configureWebPush();
    $user = User::factory()->create();
    $other = PushSubscription::factory()->for($user)->create(['label' => 'Firefox on Linux', 'vapid_public_key' => 'BFakeVapidPublicKey']);
    $this->actingAs($user);

    visit(route('settings.notifications.edit', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-push-device]', 'Firefox on Linux')
        ->click(sprintf('[data-push-device="%d"] [data-push-remove]', $other->id))
        ->assertSee('Device removed.')
        ->assertMissing('[data-push-device]');

    expect(PushSubscription::query()->count())->toBe(0);
});
