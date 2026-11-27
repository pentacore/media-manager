<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * The server's VAPID identity for Web Push. Web push is available only when
 * both keys are non-empty strings.
 */
final readonly class WebPushConfig
{
    private ?string $publicKey;

    private ?string $privateKey;

    private ?string $subject;

    public function __construct(?string $publicKey, ?string $privateKey, ?string $subject)
    {
        $this->publicKey = self::string($publicKey);
        $this->privateKey = self::string($privateKey);
        $this->subject = self::string($subject);
    }

    public static function fromConfig(): self
    {
        return new self(
            self::string(config('services.webpush.public_key')),
            self::string(config('services.webpush.private_key')),
            self::string(config('services.webpush.subject')),
        );
    }

    public function available(): bool
    {
        return $this->publicKey !== null && $this->privateKey !== null;
    }

    public function publicKey(): ?string
    {
        return $this->publicKey;
    }

    public function privateKey(): ?string
    {
        return $this->privateKey;
    }

    public function subject(): string
    {
        return $this->subject ?? self::string(config('app.url')) ?? 'http://localhost';
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
