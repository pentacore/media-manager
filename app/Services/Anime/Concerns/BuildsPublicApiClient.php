<?php

declare(strict_types=1);

namespace App\Services\Anime\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Shared HTTP client configuration for the public (key-less) anime metadata
 * APIs (AniList, Jikan). Keeps timeout/retry policy in one place so it cannot
 * drift between clients.
 */
trait BuildsPublicApiClient
{
    private function publicApiClient(string $label): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(15)
            ->connectTimeout(5)
            ->withUserAgent('MediaManager/'.config('app.version').' '.$label)
            ->retry(3, fn (int $attempt): int => $attempt * 500, throw: false);
    }
}
