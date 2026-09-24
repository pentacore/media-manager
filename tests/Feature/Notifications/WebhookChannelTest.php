<?php

declare(strict_types=1);

use App\Notifications\Channels\WebhookChannel;
use App\Services\Notifications\PushMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-04 12:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('deliver posts the json payload with event header and hmac signature', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);

    new WebhookChannel()->deliver(
        ['url' => 'https://hooks.example.com/mm', 'secret' => 's3cret'],
        new PushMessage(severity: 'warning', title: 'T', body: 'B', url: 'https://mm.example.com/x'),
    );

    Http::assertSent(function ($request): bool {
        $body = $request->body();
        $decoded = json_decode($body, true);

        return $request->url() === 'https://hooks.example.com/mm'
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->hasHeader('X-MediaManager-Event', 'notification')
            && $request->hasHeader('X-MediaManager-Signature', 'sha256='.hash_hmac('sha256', $body, 's3cret'))
            && $decoded === [
                'event' => 'notification',
                'severity' => 'warning',
                'title' => 'T',
                'message' => 'B',
                'url' => 'https://mm.example.com/x',
                'sent_at' => '2026-09-04T12:00:00+00:00',
            ];
    });
});

test('deliver sends no signature header without a secret', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);

    new WebhookChannel()->deliver(['url' => 'https://hooks.example.com/mm', 'secret' => null], new PushMessage(severity: 'info', title: 'T', body: 'B'));

    Http::assertSent(fn ($request): bool => ! $request->hasHeader('X-MediaManager-Signature'));
});

test('deliver throws on a non-2xx response', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response('nope', 500)]);

    new WebhookChannel()->deliver(['url' => 'https://hooks.example.com/mm', 'secret' => null], new PushMessage(severity: 'info', title: 'T', body: 'B'));
})->throws(RequestException::class);
