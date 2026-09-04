<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Services\Notifications\PushMessage;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends a message through the Telegram Bot API. The bot token is global
 * config; the route is the chat id (a user's private chat or a group).
 */
class TelegramChannel extends PushChannel
{
    public const string DRIVER = 'telegram';

    public function deliver(mixed $route, PushMessage $message): void
    {
        $token = config('services.telegram.token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $lines = [sprintf('<b>%s</b>', e($message->title)), e($message->body)];

        if ($message->url !== null && $message->url !== '') {
            $lines[] = e($message->url);
        }

        Http::timeout(5)
            ->post(sprintf('https://api.telegram.org/bot%s/sendMessage', $token), [
                'chat_id' => (string) $route,
                'text' => mb_substr(implode("\n", $lines), 0, 4096),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ])
            ->throw();
    }

    public function label(): string
    {
        return 'Telegram';
    }
}
