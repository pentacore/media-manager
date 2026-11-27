<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\ServiceWarning;
use App\Services\Notifications\PreferenceResolver;
use App\Services\Notifications\WebPushConfig;
use App\Services\Notifications\WebPushResult;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    app()->instance(WebPushConfig::class, new WebPushConfig('test-vapid-public-key', 'private', 'mailto:a@example.test', ['push.example.test']));
});

test('it sends the push message to every device of the user', function (string $severity, string $urgency): void {
    $user = User::factory()->create();
    PushSubscription::factory()->count(2)->for($user)->create();
    $fake = fakeWebPushSender();

    $notification = new ServiceWarning('sonarr', 'Health issue', 'Indexer down', $severity);
    new WebPushChannel()->send($user, $notification);

    $message = $notification->toPush($user);
    expect($fake->sent)->toHaveCount(2)
        ->and(json_decode($fake->sent[0]['payload'], true))->toBe([
            'title' => $message->title,
            'body' => $message->body,
            'url' => $message->url,
            'severity' => $message->severity,
            'tag' => 'mm-'.substr(sha1($message->title), 0, 12),
        ])
        ->and($fake->sent[0]['options'])->toBe(['TTL' => 86400, 'urgency' => $urgency]);
})->with([
    'warning' => ['warning', 'normal'],
    'error' => ['error', 'high'],
]);

test('a device the push service reports gone is deleted', function (): void {
    $user = User::factory()->create();
    $gone = PushSubscription::factory()->for($user)->create();
    $kept = PushSubscription::factory()->for($user)->create();
    fakeWebPushSender([$gone->endpoint => new WebPushResult(false, true, 'Gone')]);

    new WebPushChannel()->send($user, new ServiceWarning('sonarr', 'T', 'M', 'warning'));

    expect(PushSubscription::query()->find($gone->id))->toBeNull()
        ->and(PushSubscription::query()->find($kept->id))->not->toBeNull();
});

test('a failing device is kept and the others still receive', function (): void {
    $user = User::factory()->create();
    $failing = PushSubscription::factory()->for($user)->create();
    $ok = PushSubscription::factory()->for($user)->create();
    fakeWebPushSender([$failing->endpoint => new WebPushResult(false, false, 'Service Unavailable')]);

    new WebPushChannel()->send($user, new ServiceWarning('sonarr', 'T', 'M', 'warning'));

    expect(PushSubscription::query()->find($failing->id))->not->toBeNull()
        ->and($failing->refresh()->last_used_at)->toBeNull()
        ->and($ok->refresh()->last_used_at)->not->toBeNull();
});

test('deliver throws when every device failed for another reason', function (): void {
    $user = User::factory()->create();
    $device = PushSubscription::factory()->for($user)->create();
    fakeWebPushSender([$device->endpoint => new WebPushResult(false, false, 'Service Unavailable')]);

    expect(fn () => new WebPushChannel()->deliver([$device], new ServiceWarning('sonarr', 'T', 'M', 'warning')->toPush($user)))
        ->toThrow(RuntimeException::class, 'Service Unavailable');

    Log::spy();
    new WebPushChannel()->send($user, new ServiceWarning('sonarr', 'T', 'M', 'warning'));
    Log::shouldHaveReceived('warning')->once();
});

test('rows made with another VAPID key are deleted without sending', function (): void {
    $user = User::factory()->create();
    $stale = PushSubscription::factory()->for($user)->create(['vapid_public_key' => 'old-key']);
    $current = PushSubscription::factory()->for($user)->create();
    $fake = fakeWebPushSender();

    new WebPushChannel()->send($user, new ServiceWarning('sonarr', 'T', 'M', 'warning'));

    expect(PushSubscription::query()->find($stale->id))->toBeNull()
        ->and($fake->sent)->toHaveCount(1)
        ->and($fake->sent[0]['endpoint'])->toBe($current->endpoint);
});

test('nothing is sent when web push is not configured or the user has no devices', function (): void {
    $user = User::factory()->create();
    $fake = fakeWebPushSender();
    Log::spy();

    new WebPushChannel()->send($user, new ServiceWarning('sonarr', 'T', 'M', 'warning'));

    PushSubscription::factory()->for($user)->create();
    app()->instance(WebPushConfig::class, new WebPushConfig(null, null, null));
    new WebPushChannel()->send($user, new ServiceWarning('sonarr', 'T', 'M', 'warning'));

    expect($fake->sent)->toBe([])
        ->and($user->routeNotificationForWebpush())->toBeNull();
    Log::shouldNotHaveReceived('warning');
});

test('webpush resolves to the web push channel when enabled and is off by default', function (): void {
    $user = User::factory()->create();
    $resolver = resolve(PreferenceResolver::class);

    expect($resolver->channelsFor($user, ServiceWarning::class, 'warning'))->not->toContain(WebPushChannel::class);

    NotificationPreference::query()->create([
        'user_id' => $user->id,
        'notification_class' => ServiceWarning::class,
        'severity' => 'warning',
        'database' => false,
        'broadcast' => false,
        'mail' => false,
        'webpush' => true,
    ]);

    expect($resolver->channelsFor($user, ServiceWarning::class, 'warning'))->toBe([WebPushChannel::class]);
});

test('a stored device on a now-disallowed host is deleted and not sent to', function (): void {
    $user = User::factory()->create();
    $bad = PushSubscription::factory()->for($user)->create(['endpoint' => 'https://internal.attacker.test/x']);
    $fake = fakeWebPushSender();

    new WebPushChannel()->send($user, new ServiceWarning('sonarr', 'T', 'M', 'warning'));

    expect($fake->sent)->toBe([])
        ->and(PushSubscription::query()->find($bad->id))->toBeNull();
});
