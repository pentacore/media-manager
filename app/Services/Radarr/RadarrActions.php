<?php

declare(strict_types=1);

namespace App\Services\Radarr;

use App\Cache\Services\RadarrCache;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Arr\ArrClient;
use App\Services\Arr\ArrConnections;
use App\Services\Arr\ArrLibraryActions;

/**
 * @extends ArrLibraryActions<RadarrClient>
 */
class RadarrActions extends ArrLibraryActions
{
    protected function serviceType(): ServiceType
    {
        return ServiceType::Radarr;
    }

    protected function itemNoun(): string
    {
        return 'movie';
    }

    protected function libraryIdKey(): string
    {
        return 'radarr_movie_id';
    }

    protected function itemIdKey(): string
    {
        return 'movie_id';
    }

    protected function externalIdKey(): string
    {
        return 'tmdb_id';
    }

    protected function lookupTerm(int $externalId): string
    {
        return sprintf('tmdb:%d', $externalId);
    }

    protected function client(ServiceConnection $serviceConnection): RadarrClient
    {
        return resolve(ArrConnections::class)->radarr($serviceConnection);
    }

    protected function cache(ServiceConnection $serviceConnection): RadarrCache
    {
        return new RadarrCache($serviceConnection);
    }

    /**
     * @param  RadarrClient  $client
     * @return array<int, array<string, mixed>>
     */
    protected function lookup(ArrClient $client, string $term): array
    {
        return $client->searchMovies($term);
    }

    /**
     * @param  RadarrClient  $client
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function add(ArrClient $client, array $item): array
    {
        return $client->addMovie($item);
    }

    /**
     * @param  RadarrClient  $client
     * @return array<string, mixed>
     */
    protected function fetchFresh(ArrClient $client, int $itemId): array
    {
        return $client->fetchMovieById($itemId);
    }

    /**
     * @param  RadarrClient  $client
     * @param  array<string, mixed>  $item
     */
    protected function update(ArrClient $client, int $itemId, array $item): void
    {
        $client->updateMovie($itemId, $item);
    }

    /**
     * @param  RadarrClient  $client
     */
    protected function delete(ArrClient $client, int $itemId, bool $deleteFiles): void
    {
        $client->deleteMovie($itemId, $deleteFiles);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function addOptions(array $payload): array
    {
        return ['addOptions' => ['searchForMovie' => true]];
    }

    protected function replacementInFlight(int $serviceConnectionId, int $itemId): bool
    {
        return $this->pendingReplacementGuard->inFlightForMedia($serviceConnectionId, movieId: $itemId);
    }
}
