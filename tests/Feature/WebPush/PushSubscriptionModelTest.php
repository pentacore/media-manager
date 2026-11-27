<?php

declare(strict_types=1);

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\WebPushConfig;

test('deleting a user deletes their push subscriptions', function (): void {
    $user = User::factory()->create();
    PushSubscription::factory()->for($user)->count(2)->create();
    $other = PushSubscription::factory()->create();

    $user->delete();

    expect(PushSubscription::query()->count())->toBe(1)
        ->and(PushSubscription::query()->first()?->is($other))->toBeTrue();
});

test('push subscription hides its secrets and defaults its encoding', function (): void {
    $subscription = PushSubscription::factory()->create()->fresh();

    expect($subscription->content_encoding)->toBe('aes128gcm')
        ->and($subscription->toArray())->not->toHaveKeys(['auth_token', 'public_key']);
});

test('WebPushConfig is available only with both keys', function (?string $public, ?string $private, bool $available): void {
    $config = new WebPushConfig(
        publicKey: $public,
        privateKey: $private,
        subject: 'mailto:admin@example.com',
    );

    expect($config->available())->toBe($available);
})->with([
    'both set' => ['pub', 'priv', true],
    'public empty' => ['', 'priv', false],
    'private empty' => ['pub', '', false],
    'public null' => [null, 'priv', false],
    'private null' => ['pub', null, false],
]);

test('WebPushConfig reads services config and falls back to the app url for the subject', function (): void {
    config([
        'services.webpush.public_key' => 'pub',
        'services.webpush.private_key' => 'priv',
        'services.webpush.subject' => null,
        'app.url' => 'https://media.example.com',
    ]);

    $config = resolve(WebPushConfig::class);

    expect($config->available())->toBeTrue()
        ->and($config->publicKey())->toBe('pub')
        ->and($config->privateKey())->toBe('priv')
        ->and($config->subject())->toBe('https://media.example.com');
});
