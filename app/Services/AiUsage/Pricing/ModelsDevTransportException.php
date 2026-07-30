<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Throwable;

/**
 * Classified failure raised by {@see ModelsDevPricingClient} when the Models.dev
 * pricing catalog cannot be fetched or is not a usable shape.
 *
 * The {@see self::$category} is a stable machine-readable identifier callers can
 * branch on (retry policy, alerting, provenance). Messages are intentionally
 * generic: the full upstream response body is never embedded so oversized or
 * sensitive payloads cannot leak into logs or exception trackers.
 */
final class ModelsDevTransportException extends RuntimeException
{
    /**
     * The connection could not be established (DNS, refused, reset).
     */
    public const string CATEGORY_CONNECTION = 'connection';

    /**
     * The request exceeded the configured timeout before completing.
     */
    public const string CATEGORY_TIMEOUT = 'timeout';

    /**
     * The upstream rate limited the request (HTTP 429).
     */
    public const string CATEGORY_RATE_LIMITED = 'rate_limited';

    /**
     * The upstream returned a server error (HTTP 5xx) that persisted through
     * every retry attempt.
     */
    public const string CATEGORY_SERVER_ERROR = 'server_error';

    /**
     * The upstream returned a deterministic client error (non-429 HTTP 4xx)
     * that is never retried.
     */
    public const string CATEGORY_CLIENT_ERROR = 'client_error';

    /**
     * The raw response body exceeded the configured byte ceiling.
     */
    public const string CATEGORY_OVERSIZED = 'oversized';

    /**
     * The response body was not valid JSON.
     */
    public const string CATEGORY_INVALID_JSON = 'invalid_json';

    /**
     * The decoded payload was not a top-level associative provider object.
     */
    public const string CATEGORY_INVALID_SHAPE = 'invalid_shape';

    private function __construct(
        public readonly string $category,
        string $message,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Classify a low-level connection failure as a timeout or a plain
     * connection error based on the underlying cURL/Guzzle message.
     */
    public static function fromConnectionException(ConnectionException $connectionException): self
    {
        $message = strtolower($connectionException->getMessage());

        $isTimeout = str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'operation too slow');

        return new self(
            category: $isTimeout ? self::CATEGORY_TIMEOUT : self::CATEGORY_CONNECTION,
            message: $isTimeout
                ? 'Timed out contacting the Models.dev pricing API.'
                : 'Could not connect to the Models.dev pricing API.',
            previous: $connectionException,
        );
    }

    /**
     * Classify a non-successful HTTP status. The status is retained for
     * telemetry, but the response body is deliberately omitted.
     */
    public static function fromResponseStatus(int $status): self
    {
        $category = match (true) {
            $status === 429 => self::CATEGORY_RATE_LIMITED,
            $status >= 500 => self::CATEGORY_SERVER_ERROR,
            default => self::CATEGORY_CLIENT_ERROR,
        };

        return new self(
            category: $category,
            message: sprintf('Models.dev pricing API responded with HTTP %d.', $status),
            status: $status,
        );
    }

    public static function oversized(int $actualBytes, int $maxBytes): self
    {
        return new self(
            category: self::CATEGORY_OVERSIZED,
            message: sprintf(
                'Models.dev pricing response of %d bytes exceeds the %d byte ceiling.',
                $actualBytes,
                $maxBytes,
            ),
        );
    }

    public static function invalidJson(string $reason): self
    {
        return new self(
            category: self::CATEGORY_INVALID_JSON,
            message: sprintf('Models.dev pricing response was not valid JSON (%s).', $reason),
        );
    }

    public static function invalidShape(): self
    {
        return new self(
            category: self::CATEGORY_INVALID_SHAPE,
            message: 'Models.dev pricing response was not a top-level provider object.',
        );
    }
}
