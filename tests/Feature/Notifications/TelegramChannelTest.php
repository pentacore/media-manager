<?php

declare(strict_types=1);

use App\Notifications\Channels\TelegramChannel;
use App\Services\Notifications\PushMessage;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('services.telegram.token', '123:abc');
});

test('deliver calls sendMessage with html-escaped text and the chat id', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    new TelegramChannel()->deliver('-1001', new PushMessage(severity: 'warning', title: 'A <b>', body: 'x & y', url: 'https://mm.example.com/h'));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bot123:abc/sendMessage'
        && $request['chat_id'] === '-1001'
        && $request['parse_mode'] === 'HTML'
        && $request['disable_web_page_preview'] === true
        && $request['text'] === "<b>A &lt;b&gt;</b>\nx &amp; y\nhttps://mm.example.com/h");
});

test('deliver throws when the bot token is not configured', function (): void {
    config()->set('services.telegram.token', null);
    Http::fake();

    new TelegramChannel()->deliver('-1001', new PushMessage(severity: 'info', title: 'T', body: 'B'));
})->throws(RuntimeException::class, 'Telegram bot token is not configured.');

test('deliver throws on a non-2xx response', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);

    new TelegramChannel()->deliver('-1001', new PushMessage(severity: 'info', title: 'T', body: 'B'));
})->throws(RequestException::class);
