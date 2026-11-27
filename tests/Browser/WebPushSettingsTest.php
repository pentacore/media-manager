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
function fakePushBrowserScript(bool $rejectSubscribe = false, bool $staleExisting = false): string
{
    $endpoint = WEB_PUSH_TEST_ENDPOINT;
    $reject = $rejectSubscribe ? 'true' : 'false';
    $stale = $staleExisting ? 'true' : 'false';

    return <<<JS
        (() => {
            window.__pushCalls = [];
            const make = (key) => ({
                endpoint: '{$endpoint}',
                options: { applicationServerKey: key },
                toJSON: () => ({ endpoint: '{$endpoint}', keys: { p256dh: 'BAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', auth: 'AAAAAAAAAAAAAAAAAAAAAA' } }),
                unsubscribe: async () => {
                    window.__pushCalls.push('unsubscribe');
                    current = null;
                    return true;
                },
            });
            let current = {$stale} ? make(new Uint8Array([1, 2, 3]).buffer) : null;
            const registration = {
                pushManager: {
                    getSubscription: async () => current,
                    subscribe: async () => {
                        window.__pushCalls.push('subscribe');
                        if ({$reject}) {
                            throw new Error('Push service unreachable');
                        }
                        return (current = make(null));
                    },
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

test('a failing push service shows an error and keeps the card usable', function (): void {
    configureWebPush();
    $this->actingAs(User::factory()->create());

    $webpage = visit(route('settings.notifications.edit', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(fakePushBrowserScript(rejectSubscribe: true));
    $webpage->click('[data-push-enable]')
        ->assertSeeIn('[data-push-error]', 'Push service unreachable')
        ->assertPresent('[data-push-enable]')
        ->assertMissing('[data-push-device]');

    expect(PushSubscription::query()->count())->toBe(0);
});

test('a subscription made with a rotated server key is replaced before it is registered', function (): void {
    configureWebPush();
    $this->actingAs(User::factory()->create());

    $webpage = visit(route('settings.notifications.edit', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(fakePushBrowserScript(staleExisting: true));
    $webpage->click('[data-push-enable]')
        ->assertCount('[data-push-device]', 1)
        ->assertScript('JSON.stringify(window.__pushCalls)', '["unsubscribe","subscribe"]');

    expect(PushSubscription::query()->sole()->endpoint)->toBe(WEB_PUSH_TEST_ENDPOINT);
});

test('the service worker only follows same-origin notification urls', function (): void {
    $this->actingAs(User::factory()->create());

    $webpage = visit(route('settings.notifications.edit', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertScript(<<<'JS_WRAP'
        (async () => {
            const code = await (await fetch('/sw.js')).text();
            const fake = { addEventListener() {}, location: { origin: location.origin } };
            const resolve = new Function('self', code + '; return resolveTargetUrl;')(fake);
    
            return JSON.stringify([
                resolve('/settings/notifications').replace(location.origin, ''),
                resolve('https://evil.example.com/x'),
                resolve('//evil.example.com/x'),
                resolve('javascript:alert(1)'),
                resolve(undefined),
                resolve('http://[bad'),
            ]);
        })()
    JS_WRAP, json_encode([
        '/settings/notifications',
        '/',
        '/',
        '/',
        '/',
        '/',
    ], JSON_UNESCAPED_SLASHES));
});
