<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Shared transport for every pricing source client.
 *
 * GETs the URL configured under `mediamanager.ai.pricing.{source}` and returns
 * the decoded JSON, or raises a classified {@see PricingTransportException}.
 * It does no DB work and does not interpret the payload's shape — each client
 * checks its own top-level shape.
 *
 * Retries are bounded and limited to transient failures (connection/timeout,
 * HTTP 429, and HTTP 5xx). Deterministic client errors (non-429 4xx),
 * oversized bodies, and invalid JSON are never retried.
 */
final class PricingFeedFetcher
{
    /**
     * @param  string  $configKey  Key under `mediamanager.ai.pricing` holding url, timeouts, retries, and max_response_bytes.
     * @param  string  $sourceLabel  Human-readable source name used in failure messages.
     * @param  string  $clientName  Calling client's short class name, appended to the user agent.
     * @param  array<string, string>  $headers  Extra request headers (for example an Authorization bearer).
     *
     * @throws PricingTransportException
     */
    public function fetch(string $configKey, string $sourceLabel, string $clientName, array $headers = []): mixed
    {
        $configPath = sprintf('mediamanager.ai.pricing.%s', $configKey);

        $connectTimeout = (int) config(sprintf('%s.connect_timeout', $configPath));
        $timeout = (int) config(sprintf('%s.timeout', $configPath));
        $retries = (int) config(sprintf('%s.retries', $configPath));
        $maxBytes = (int) config(sprintf('%s.max_response_bytes', $configPath));
        $url = (string) config(sprintf('%s.url', $configPath));

        $attempts = max(1, $retries + 1);

        try {
            $response = Http::acceptJson()
                ->withHeaders($headers)
                ->withUserAgent(sprintf('MediaManager/%s %s', config('app.version'), $clientName))
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->retry(
                    $attempts,
                    fn (int $attempt): int => $attempt * 1000,
                    fn (Throwable $throwable): bool => $this->isRetryable($throwable),
                    throw: false,
                )
                ->get($url);
        } catch (ConnectionException $connectionException) {
            throw PricingTransportException::fromConnectionException($connectionException, $sourceLabel);
        }

        if ($response->failed()) {
            throw PricingTransportException::fromResponseStatus($response->status(), $sourceLabel);
        }

        $body = $response->body();
        $byteLength = strlen($body);

        if ($byteLength > $maxBytes) {
            throw PricingTransportException::oversized($byteLength, $maxBytes, $sourceLabel);
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw PricingTransportException::invalidJson(json_last_error_msg(), $sourceLabel);
        }

        return $decoded;
    }

    /**
     * Whether the given failure is transient and worth retrying. Connection and
     * timeout failures always are; HTTP failures only for 429 and 5xx.
     */
    private function isRetryable(Throwable $throwable): bool
    {
        if ($throwable instanceof ConnectionException) {
            return true;
        }

        if ($throwable instanceof RequestException && $throwable->response !== null) {
            $status = $throwable->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }
}
