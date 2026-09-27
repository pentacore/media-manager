<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Throwable;

/**
 * Classified failure raised when a pricing source (models.dev, LiteLLM,
 * OpenRouter, xAI) cannot be fetched or is not a usable shape.
 *
 * The {@see self::$category} is a stable machine-readable identifier recorded
 * per source on the refresh run. Messages name the source but never embed the
 * upstream response body, so oversized or sensitive payloads cannot leak into
 * logs or exception trackers.
 */
final class PricingTransportException extends RuntimeException
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
     * The decoded payload did not have the source's expected top-level shape.
     */
    public const string CATEGORY_INVALID_SHAPE = 'invalid_shape';

    /**
     * The source needs a credential that is not configured, so no request was
     * made. Not an error: the run falls back to other sources quietly.
     */
    public const string CATEGORY_NOT_CONFIGURED = 'not_configured';

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
    public static function fromConnectionException(ConnectionException $connectionException, string $source): self
    {
        $message = strtolower($connectionException->getMessage());

        $isTimeout = str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'operation too slow');

        return new self(
            category: $isTimeout ? self::CATEGORY_TIMEOUT : self::CATEGORY_CONNECTION,
            message: $isTimeout
                ? sprintf('Timed out contacting the %s pricing API.', $source)
                : sprintf('Could not connect to the %s pricing API.', $source),
            previous: $connectionException,
        );
    }

    /**
     * Classify a non-successful HTTP status. The status is retained for
     * telemetry, but the response body is deliberately omitted.
     */
    public static function fromResponseStatus(int $status, string $source): self
    {
        $category = match (true) {
            $status === 429 => self::CATEGORY_RATE_LIMITED,
            $status >= 500 => self::CATEGORY_SERVER_ERROR,
            default => self::CATEGORY_CLIENT_ERROR,
        };

        return new self(
            category: $category,
            message: sprintf('%s pricing API responded with HTTP %d.', $source, $status),
            status: $status,
        );
    }

    public static function oversized(int $actualBytes, int $maxBytes, string $source): self
    {
        return new self(
            category: self::CATEGORY_OVERSIZED,
            message: sprintf('%s pricing response of %d bytes exceeds the %d byte ceiling.', $source, $actualBytes, $maxBytes),
        );
    }

    public static function invalidJson(string $reason, string $source): self
    {
        return new self(
            category: self::CATEGORY_INVALID_JSON,
            message: sprintf('%s pricing response was not valid JSON (%s).', $source, $reason),
        );
    }

    public static function invalidShape(string $source, string $expected): self
    {
        return new self(
            category: self::CATEGORY_INVALID_SHAPE,
            message: sprintf('%s pricing response was not %s.', $source, $expected),
        );
    }

    public static function notConfigured(string $source): self
    {
        return new self(
            category: self::CATEGORY_NOT_CONFIGURED,
            message: sprintf('%s pricing API credentials are not configured.', $source),
        );
    }
}
