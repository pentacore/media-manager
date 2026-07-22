<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Transport for the public Models.dev pricing catalog.
 *
 * Fetches `mediamanager.ai.pricing.models_dev.url` over HTTP and returns the
 * decoded top-level provider map, or raises a classified
 * {@see ModelsDevTransportException}. This client is transport-only: it does no
 * DB work and does not interpret provider/model shapes — that is the adapter's
 * job.
 *
 * Retries are bounded and limited to transient failures (connection/timeout,
 * HTTP 429, and HTTP 5xx). Deterministic client errors (non-429 4xx), oversized
 * bodies, and invalid JSON are never retried because a repeat request would
 * return the same result.
 */
final class ModelsDevPricingClient
{
    /**
     * Fetch and decode the Models.dev pricing catalog.
     *
     * @return array<string, mixed> Top-level provider map keyed by upstream provider id.
     *
     * @throws ModelsDevTransportException
     */
    public function fetch(): array
    {
        $connectTimeout = (int) config('mediamanager.ai.pricing.models_dev.connect_timeout');
        $timeout = (int) config('mediamanager.ai.pricing.models_dev.timeout');
        $retries = (int) config('mediamanager.ai.pricing.models_dev.retries');
        $maxBytes = (int) config('mediamanager.ai.pricing.models_dev.max_response_bytes');
        $url = (string) config('mediamanager.ai.pricing.models_dev.url');

        $attempts = max(1, $retries + 1);

        try {
            $response = Http::acceptJson()
                ->withUserAgent('MediaManager/'.config('app.version').' '.class_basename($this))
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
            throw ModelsDevTransportException::fromConnectionException($connectionException);
        }

        if ($response->failed()) {
            throw ModelsDevTransportException::fromResponseStatus($response->status());
        }

        $body = $response->body();
        $byteLength = strlen($body);

        if ($byteLength > $maxBytes) {
            throw ModelsDevTransportException::oversized($byteLength, $maxBytes);
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw ModelsDevTransportException::invalidJson(json_last_error_msg());
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw ModelsDevTransportException::invalidShape();
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
