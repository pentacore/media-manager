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

    private const int MAX_TITLE = 200;

    private const int MAX_BODY = 1000;

    /** Devices the last deliver() call reached successfully. */
    public int $delivered = 0;

    public function deliver(mixed $route, PushMessage $message): void
    {
        if (! is_array($route) || $route === []) {
            return;
        }

        $this->delivered = 0;
        $config = resolve(WebPushConfig::class);

        if (! $config->available()) {
            return;
        }

        $sender = resolve(WebPushSender::class);
        $title = $this->truncate($message->title, self::MAX_TITLE);
        $body = $this->truncate($message->body, self::MAX_BODY);
        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $message->url,
            'severity' => $message->severity,
            'tag' => 'mm-'.substr(sha1($message->title."\n".$message->body."\n".($message->url ?? '')), 0, 12),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $options = ['TTL' => self::TTL, 'urgency' => $message->severity === 'error' ? 'high' : 'normal'];

        $attempted = 0;
        $failures = [];

        foreach ($route as $subscription) {
            if (! $subscription instanceof PushSubscription) {
                continue;
            }

            if ($subscription->vapid_public_key !== $config->publicKey() || ! $config->allowsEndpoint($subscription->endpoint)) {
                $subscription->delete();

                continue;
            }

            $attempted++;
            $result = $sender->send($subscription, $payload, $options);

            if ($result->success) {
                $this->delivered++;
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

    /**
     * A push payload may not exceed ~4 KB encrypted, so long texts are cut.
     */
    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    public function label(): string
    {
        return 'Web push';
    }
}
