<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\PushSubscription;
use App\Services\Notifications\PushMessage;
use App\Services\Notifications\WebPushConfig;
use App\Services\Notifications\WebPushSender;
use RuntimeException;

/**
 * Pushes a notification to every browser the user subscribed with. Devices
 * the push service reports gone, and devices subscribed under a different
 * VAPID key, are deleted. A failing device is kept and the others still
 * receive; deliver() throws only when every device failed.
 */
class WebPushChannel extends PushChannel
{
    public const string DRIVER = 'webpush';

    private const int TTL = 86400;

    public function deliver(mixed $route, PushMessage $message): void
    {
        if (! is_array($route) || $route === []) {
            return;
        }

        $config = resolve(WebPushConfig::class);

        if (! $config->available()) {
            return;
        }

        $sender = resolve(WebPushSender::class);
        $payload = json_encode([
            'title' => $message->title,
            'body' => $message->body,
            'url' => $message->url,
            'severity' => $message->severity,
            'tag' => 'mm-'.substr(sha1($message->title), 0, 12),
        ], JSON_THROW_ON_ERROR);
        $options = ['TTL' => self::TTL, 'urgency' => $message->severity === 'error' ? 'high' : 'normal'];

        $attempted = 0;
        $failures = [];

        foreach ($route as $subscription) {
            if (! $subscription instanceof PushSubscription) {
                continue;
            }

            if ($subscription->vapid_public_key !== $config->publicKey()) {
                $subscription->delete();

                continue;
            }

            $attempted++;
            $result = $sender->send($subscription, $payload, $options);

            if ($result->success) {
                $subscription->forceFill(['last_used_at' => now()])->save();
            } elseif ($result->expired) {
                $subscription->delete();
            } else {
                $failures[] = $result->reason ?? 'unknown error';
            }
        }

        if ($attempted > 0 && count($failures) === $attempted) {
            throw new RuntimeException(implode('; ', array_unique($failures)));
        }
    }

    public function label(): string
    {
        return 'Web push';
    }
}
