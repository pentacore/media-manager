<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use Illuminate\JsonSchema\Types\StringType;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Seerr\SeerrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class SearchMediaTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Search across Sonarr (TV series), Radarr (movies), and Seerr (requests) for media matching the query. Returns up to 5 hits per source.';
    }

    public function handle(Request $request): Stringable|string
    {
        $args = $request->toArray();
        $query = trim((string) ($args['query'] ?? ''));

        if ($query === '') {
            return json_encode(['error' => 'query is required']);
        }

        $results = [
            'series' => $this->safeCall(fn (): array => $this->sonarrSearch($query)),
            'movies' => $this->safeCall(fn (): array => $this->radarrSearch($query)),
            'requests' => $this->safeCall(fn (): array => $this->seerrSearch($query)),
        ];

        return json_encode($results);
    }

    /**
     * @return StringType[]
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The search term to look up (title fragment, year, etc).')->required(),
        ];
    }

    /**
     * @param  callable(): array<int, array<string, mixed>>  $fn
     * @return array<int, array<string, mixed>>
     */
    private function safeCall(callable $fn): array
    {
        try {
            return $fn();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sonarrSearch(string $query): array
    {
        $sonarrClient = new SonarrClient(ServiceConnection::resolveActive(ServiceType::Sonarr));
        $results = array_slice($sonarrClient->searchSeries($query), 0, 5);

        return array_map(fn (array $i): array => [
            'tvdb_id' => $i['tvdbId'] ?? null,
            'title' => $i['title'] ?? null,
            'year' => $i['year'] ?? null,
            'overview' => mb_substr((string) ($i['overview'] ?? ''), 0, 300),
        ], $results);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function radarrSearch(string $query): array
    {
        $radarrClient = new RadarrClient(ServiceConnection::resolveActive(ServiceType::Radarr));
        $results = array_slice($radarrClient->searchMovies($query), 0, 5);

        return array_map(fn (array $i): array => [
            'tmdb_id' => $i['tmdbId'] ?? null,
            'title' => $i['title'] ?? null,
            'year' => $i['year'] ?? null,
            'overview' => mb_substr((string) ($i['overview'] ?? ''), 0, 300),
        ], $results);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function seerrSearch(string $query): array
    {
        $seerrClient = new SeerrClient(ServiceConnection::resolveActive(ServiceType::Seerr));
        $response = $seerrClient->search($query);
        $results = $response['results'] ?? $response;

        if (! is_array($results)) {
            return [];
        }

        return array_map(fn (array $i): array => [
            'id' => $i['id'] ?? null,
            'media_type' => $i['mediaType'] ?? null,
            'title' => $i['title'] ?? $i['name'] ?? null,
            'overview' => mb_substr((string) ($i['overview'] ?? ''), 0, 300),
        ], array_slice($results, 0, 5));
    }
}
