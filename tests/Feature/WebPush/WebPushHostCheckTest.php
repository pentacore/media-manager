<?php

declare(strict_types=1);

use App\Models\PushSubscription;
use App\Services\Notifications\WebPushConfig;
use App\Services\Notifications\WebPushSender;
use Illuminate\Support\Env;

test('allowsEndpoint normalises case and a single trailing dot', function (string $url): void {
    expect(new WebPushConfig('p', 'k', null)->allowsEndpoint($url))->toBeTrue();
})->with([
    'uppercase' => 'https://FCM.GoogleAPIs.com/x',
    'trailing dot' => 'https://fcm.googleapis.com./x',
    'wildcard uppercase' => 'https://Updates.Push.Apple.com/x',
]);

test('allowsEndpoint rejects malformed hosts', function (string $url): void {
    expect(new WebPushConfig('p', 'k', null)->allowsEndpoint($url))->toBeFalse();
})->with([
    'double trailing dot' => 'https://fcm.googleapis.com../x',
    'encoded backslash' => 'https://evil.com%5C.push.apple.com/x',
    'ipv6 literal' => 'https://[::1]/x',
    'underscore' => 'https://a_b.push.apple.com/x',
]);

test('the sender refuses a disallowed endpoint without contacting a push service', function (): void {
    $subscription = PushSubscription::factory()->make(['endpoint' => 'https://evil.example.com/x']);

    $result = new WebPushSender(new WebPushConfig('p', 'k', 'mailto:a@example.com'))->send($subscription, '{}', []);

    expect($result->success)->toBeFalse()
        ->and($result->expired)->toBeTrue();
});

test('WEBPUSH_ALLOWED_HOSTS is parsed from a comma-separated list', function (): void {
    $repository = Env::getRepository();
    $repository->set('WEBPUSH_ALLOWED_HOSTS', ' push.one.test , *.push.two.test,, ');

    try {
        $config = require config_path('services.php');
    } finally {
        $repository->clear('WEBPUSH_ALLOWED_HOSTS');
    }

    expect($config['webpush']['allowed_hosts'])->toBe(['push.one.test', '*.push.two.test']);
});
