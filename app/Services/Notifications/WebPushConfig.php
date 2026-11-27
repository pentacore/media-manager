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

    /**
     * Push services a subscription endpoint may point at. An entry `host`
     * matches exactly; `*.host` matches any subdomain of `host`.
     */
    public const array DEFAULT_ALLOWED_HOSTS = [
        'fcm.googleapis.com',
        '*.push.services.mozilla.com',
        '*.notify.windows.com',
        '*.push.apple.com',
    ];

    /** @var list<string> */
    private array $allowedHosts;

    /**
     * @param  list<string>|null  $allowedHosts  null uses DEFAULT_ALLOWED_HOSTS
     */
    public function __construct(?string $publicKey, ?string $privateKey, ?string $subject, ?array $allowedHosts = null)
    {
        $this->allowedHosts = array_values(array_filter(
            array_map(fn (mixed $host): string => strtolower(trim((string) $host)), $allowedHosts ?? self::DEFAULT_ALLOWED_HOSTS),
            fn (string $host): bool => $host !== '',
        ));
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
            is_array(config('services.webpush.allowed_hosts')) ? array_values(array_map(strval(...), config('services.webpush.allowed_hosts'))) : null,
        );
    }

    /**
     * Only https URLs on an allowed push-service host, with no userinfo, no
     * custom port and no IP-literal host, may be pushed to (SSRF guard).
     */
    public function allowsEndpoint(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        $host = strtolower(rtrim($parts['host'], '.'));

        if ($host === '' || str_contains($host, ':') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        foreach ($this->allowedHosts as $allowed) {
            if (str_starts_with($allowed, '*.')) {
                if (str_ends_with($host, substr($allowed, 1)) && strlen($host) > strlen($allowed) - 1) {
                    return true;
                }
            } elseif ($host === $allowed) {
                return true;
            }
        }

        return false;
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
