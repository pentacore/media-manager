<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Services\Notifications\PushMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Posts a generic JSON payload to any HTTP endpoint, optionally signed with
 * HMAC-SHA256 over the raw body. Route shape: ['url' => string, 'secret' => ?string].
 */
class WebhookChannel extends PushChannel
{
    public const string DRIVER = 'webhook';

    public const string EVENT = 'notification';

    public function deliver(mixed $route, PushMessage $message): void
    {
        $url = (string) ($route['url'] ?? '');
        $secret = $route['secret'] ?? null;

        $body = json_encode(self::payload($message), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type' => 'application/json',
            'X-MediaManager-Event' => self::EVENT,
        ];

        if (is_string($secret) && $secret !== '') {
            $headers['X-MediaManager-Signature'] = self::signature($body, $secret);
        }

        Http::timeout(5)
            ->withHeaders($headers)
            ->withBody($body, 'application/json')
            ->post($url)
            ->throw();
    }

    /**
     * @return array{event: string, severity: string, title: string, message: string, url: ?string, sent_at: string}
     */
    public static function payload(PushMessage $message): array
    {
        return [
            'event' => self::EVENT,
            'severity' => $message->severity,
            'title' => $message->title,
            'message' => $message->body,
            'url' => $message->url,
            'sent_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    public static function signature(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    public function label(): string
    {
        return 'Webhook';
    }
}
