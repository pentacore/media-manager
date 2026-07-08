<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes a notification to the globally configured ntfy server using
 * the notifiable's per-user topic. Delivery is best-effort: failures are
 * logged and swallowed so webhook handlers and jobs never break on ntfy
 * downtime.
 */
class NtfyChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $topic = method_exists($notifiable, 'routeNotificationForNtfy')
            ? $notifiable->routeNotificationForNtfy()
            : null;

        if (! is_string($topic) || $topic === '' || ! method_exists($notification, 'toNtfy')) {
            return;
        }

        $payload = [...$notification->toNtfy($notifiable), 'topic' => $topic];

        try {
            $request = Http::timeout(5);

            $token = config('services.ntfy.token');

            if (is_string($token) && $token !== '') {
                $request = $request->withToken($token);
            }

            $request->post((string) config('services.ntfy.server'), $payload)->throw();
        } catch (Throwable $throwable) {
            Log::warning('Ntfy delivery failed', [
                'topic' => $topic,
                'notification' => $notification::class,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}
