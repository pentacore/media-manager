<?php

declare(strict_types=1);

namespace App\Services\Tmdb;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class TmdbClient
{
    protected function buildClient(): PendingRequest
    {
        $apiKey = config('services.tmdb.api_key');
        throw_if(empty($apiKey), RuntimeException::class, 'TMDB API key is not configured.');

        return Http::baseUrl(rtrim((string) config('services.tmdb.base_url'), '/'))
            ->withToken((string) $apiKey)
            ->acceptJson()
            ->timeout(10)
            ->connectTimeout(3);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getTitle(int $tmdbId, string $mediaType): array
    {
        return $this->buildClient()
            ->get($this->endpointFor($mediaType, $tmdbId))
            ->throw()
            ->json() ?? [];
    }

    private function endpointFor(string $mediaType, int $tmdbId, string $suffix = ''): string
    {
        return match ($mediaType) {
            'movie' => sprintf('/movie/%d%s', $tmdbId, $suffix),
            'tv' => sprintf('/tv/%d%s', $tmdbId, $suffix),
            default => throw new InvalidArgumentException(sprintf('Unknown media_type "%s". Expected "movie" or "tv".', $mediaType)),
        };
    }
}
