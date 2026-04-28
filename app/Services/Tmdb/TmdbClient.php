<?php

declare(strict_types=1);

namespace App\Services\Tmdb;

use App\Cache\Services\TmdbCache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class TmdbClient
{
    private ?TmdbCache $tmdbCache = null;

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
        return $this->cache()->rememberMetadata(
            $mediaType.':'.$tmdbId,
            fn (): array => $this->buildClient()
                ->get($this->endpointFor($mediaType, $tmdbId))
                ->throw()
                ->json() ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getSimilar(int $tmdbId, string $mediaType): array
    {
        return $this->cache()->rememberMetadata(
            $mediaType.':'.$tmdbId.':similar',
            fn (): array => $this->buildClient()
                ->get($this->endpointFor($mediaType, $tmdbId, '/similar'))
                ->throw()
                ->json() ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getCredits(int $tmdbId, string $mediaType): array
    {
        return $this->cache()->rememberMetadata(
            $mediaType.':'.$tmdbId.':credits',
            fn (): array => $this->buildClient()
                ->get($this->endpointFor($mediaType, $tmdbId, '/credits'))
                ->throw()
                ->json() ?? [],
        );
    }

    private function cache(): TmdbCache
    {
        return $this->tmdbCache ??= new TmdbCache;
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
