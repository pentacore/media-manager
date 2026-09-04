<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Services\Notifications\NtfyMessage;
use App\Services\Notifications\PushMessage;
use Illuminate\Support\Facades\Http;

/**
 * Publishes a notification to the globally configured ntfy server using
 * the notifiable's topic (per-user column or global destination config).
 */
class NtfyChannel extends PushChannel
{
    public const string DRIVER = 'ntfy';

    public function deliver(mixed $route, PushMessage $message): void
    {
        $payload = [
            ...NtfyMessage::for($message->severity, $message->title, $message->body, $message->url),
            'topic' => (string) $route,
        ];

        $request = Http::timeout(5);

        $token = config('services.ntfy.token');

        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }

        $request->post((string) config('services.ntfy.server'), $payload)->throw();
    }

    public function label(): string
    {
        return 'Ntfy';
    }
}
