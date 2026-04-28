<?php

declare(strict_types=1);

namespace App\Services\Trakt;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class TraktClient
{
    protected function buildClient(): PendingRequest
    {
        $clientId = config('services.trakt.client_id');
        throw_if(empty($clientId), RuntimeException::class, 'Trakt client id is not configured.');

        return Http::baseUrl(rtrim((string) config('services.trakt.base_url'), '/'))
            ->withHeaders([
                'trakt-api-key' => (string) $clientId,
                'trakt-api-version' => '2',
                'Content-Type' => 'application/json',
            ])
            ->acceptJson()
            ->timeout(10)
            ->connectTimeout(3);
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getTrending(string $mediaType): array
    {
        return $this->buildClient()
            ->get(sprintf('/%s/trending', $this->collectionFor($mediaType)))
            ->throw()
            ->json() ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getPopular(string $mediaType): array
    {
        return $this->buildClient()
            ->get(sprintf('/%s/popular', $this->collectionFor($mediaType)))
            ->throw()
            ->json() ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getList(int $listId): array
    {
        return $this->buildClient()
            ->get(sprintf('/lists/%d/items', $listId))
            ->throw()
            ->json() ?? [];
    }

    private function collectionFor(string $mediaType): string
    {
        return match ($mediaType) {
            'movie' => 'movies',
            'tv' => 'shows',
            default => throw new InvalidArgumentException(sprintf('Unknown media_type "%s". Expected "movie" or "tv".', $mediaType)),
        };
    }
}
