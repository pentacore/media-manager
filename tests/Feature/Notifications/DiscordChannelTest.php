<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\ServiceWarning;
use App\Services\Notifications\PushMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('deliver posts a colour-coded embed to the webhook url', function (): void {
    Http::fake(['discord.com/*' => Http::response('', 204)]);

    new DiscordChannel()->deliver(
        'https://discord.com/api/webhooks/1/abc',
        new PushMessage(severity: 'error', title: '[sonarr] Health', body: 'Indexer down', url: 'https://mm.example.com/health'),
    );

    Http::assertSent(fn ($request): bool => $request->url() === 'https://discord.com/api/webhooks/1/abc'
        && $request['embeds'][0]['title'] === '[sonarr] Health'
        && $request['embeds'][0]['description'] === 'Indexer down'
        && $request['embeds'][0]['url'] === 'https://mm.example.com/health'
        && $request['embeds'][0]['color'] === 0xE74C3C);
});

test('deliver omits the embed url when the message has none and uses the info colour', function (): void {
    Http::fake(['discord.com/*' => Http::response('', 204)]);

    new DiscordChannel()->deliver('https://discord.com/api/webhooks/1/abc', new PushMessage(severity: 'info', title: 'T', body: 'B'));

    Http::assertSent(fn ($request): bool => ! array_key_exists('url', $request['embeds'][0])
        && $request['embeds'][0]['color'] === 0x3498DB);
});

test('deliver throws on a non-2xx response', function (): void {
    Http::fake(['discord.com/*' => Http::response(['message' => 'Unknown Webhook'], 404)]);

    new DiscordChannel()->deliver('https://discord.com/api/webhooks/1/abc', new PushMessage(severity: 'info', title: 'T', body: 'B'));
})->throws(RequestException::class);

test('send swallows delivery failures and logs a warning', function (): void {
    Http::fake(fn () => throw new ConnectionException('down'));
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message): bool => $message === 'Discord delivery failed');

    $user = User::factory()->create(['discord_webhook_url' => 'https://discord.com/api/webhooks/1/abc']);

    expect(fn (): mixed => new DiscordChannel()->send($user, new ServiceWarning('sonarr', 'T', 'M', 'warning')))
        ->not->toThrow(Throwable::class);
});
