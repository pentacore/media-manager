<?php

declare(strict_types=1);

use App\Models\PushSubscription;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

function realSubscription(): PushSubscription
{
    [$public, $auth] = deviceKeys();

    return PushSubscription::factory()->make([
        'endpoint' => 'https://push.example.test/send/secret-device-token',
        'public_key' => $public,
        'auth_token' => $auth,
    ]);
}

test('a 201 from the push service is a success', function (): void {
    $sender = senderWith([new Response(201)], $options, $url);

    $result = $sender->send(realSubscription(), '{"title":"Hi"}', ['TTL' => 60]);

    expect($result->success)->toBeTrue()
        ->and($result->expired)->toBeFalse()
        ->and($url)->toBe('https://push.example.test/send/secret-device-token');
});

test('the http client never follows redirects and has timeouts', function (): void {
    $sender = senderWith([new Response(201)], $options);

    $sender->send(realSubscription(), '{}', []);

    expect($options['allow_redirects'])->toBeFalse()
        ->and($options['timeout'])->toBe(10)
        ->and($options['connect_timeout'])->toBe(5);
});

test('410 and 404 mean the subscription is expired', function (int $status): void {
    $result = senderWith([new Response($status)])->send(realSubscription(), '{}', []);

    expect($result->success)->toBeFalse()
        ->and($result->expired)->toBeTrue();
})->with([410, 404]);

test('a 500 is a failure but not expired', function (): void {
    $result = senderWith([new Response(500)])->send(realSubscription(), '{}', []);

    expect($result->success)->toBeFalse()
        ->and($result->expired)->toBeFalse()
        ->and($result->reason)->not->toBeNull();
});

test('a redirect is a failure and not expired', function (): void {
    $result = senderWith([new Response(301, ['Location' => 'https://evil.example.test/x'])])->send(realSubscription(), '{}', []);

    expect($result->success)->toBeFalse()
        ->and($result->expired)->toBeFalse()
        ->and($result->reason)->toContain('301');
});

test('the endpoint url never appears in a failure reason', function (): void {
    $request = new Request('POST', 'https://push.example.test/send/secret-device-token');
    $sender = senderWith([new ConnectException('cURL error 28: Operation timed out for https://push.example.test/send/secret-device-token', $request)]);

    $result = $sender->send(realSubscription(), '{}', []);

    expect($result->success)->toBeFalse()
        ->and($result->reason)->not->toContain('secret-device-token')
        ->and($result->reason)->toContain('timed out');
});

test('an oversized payload or malformed key is a failed result, not an exception', function (): void {
    $oversized = senderWith([new Response(201)])->send(realSubscription(), str_repeat('x', 5000), []);
    $malformed = senderWith([new Response(201)])->send(PushSubscription::factory()->make([
        'endpoint' => 'https://push.example.test/send/secret-device-token',
        'public_key' => 'garbage',
        'auth_token' => 'garbage',
    ]), '{}', []);

    expect($oversized->success)->toBeFalse()
        ->and($oversized->expired)->toBeFalse()
        ->and($malformed->success)->toBeFalse()
        ->and($malformed->reason)->not->toContain('secret-device-token');
});
