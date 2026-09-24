<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Services\Notifications\PushMessage;
use Illuminate\Support\Facades\Http;

/**
 * Posts a single embed to a Discord webhook URL. The route is the full
 * webhook URL (per-user column or destination config).
 */
class DiscordChannel extends PushChannel
{
    public const string DRIVER = 'discord';

    /** Embed sidebar colours per severity bucket. */
    private const array COLORS = [
        'error' => 0xE74C3C,
        'warning' => 0xF1C40F,
        'info' => 0x3498DB,
    ];

    public function deliver(mixed $route, PushMessage $message): void
    {
        $embed = [
            'title' => mb_substr($message->title, 0, 256),
            'description' => mb_substr($message->body, 0, 4096),
            'color' => self::COLORS[$message->severity] ?? self::COLORS['info'],
        ];

        if ($message->url !== null && $message->url !== '') {
            $embed['url'] = $message->url;
        }

        Http::timeout(5)->post((string) $route, ['embeds' => [$embed]])->throw();
    }

    public function label(): string
    {
        return 'Discord';
    }
}
