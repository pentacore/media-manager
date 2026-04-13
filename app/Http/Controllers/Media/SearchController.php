<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Services\Jellyseerr\JellyseerrClient;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function index(Request $request): Response
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:500'],
        ]);

        $term = trim((string) $request->query('q', ''));
        $results = [
            'series' => [],
            'movies' => [],
            'requests' => [],
        ];
        $errors = [];

        if ($term === '') {
            return Inertia::render('Search', [
                'query' => '',
                'results' => $results,
                'errors' => $errors,
            ]);
        }

        try {
            $sonarrClient = new SonarrClient(ServiceConnection::resolveActive(ServiceType::Sonarr));
            $results['series'] = array_map(fn (array $item): array => [
                'tvdb_id' => $item['tvdbId'] ?? null,
                'title' => $item['title'] ?? null,
                'year' => $item['year'] ?? null,
                'overview' => $item['overview'] ?? null,
                'remote_poster' => $item['remotePoster'] ?? null,
            ], $sonarrClient->searchSeries($term));
        } catch (\Throwable) {
            $errors[] = 'sonarr';
        }

        try {
            $radarrClient = new RadarrClient(ServiceConnection::resolveActive(ServiceType::Radarr));
            $results['movies'] = array_map(fn (array $item): array => [
                'tmdb_id' => $item['tmdbId'] ?? null,
                'title' => $item['title'] ?? null,
                'year' => $item['year'] ?? null,
                'overview' => $item['overview'] ?? null,
                'remote_poster' => $item['remotePoster'] ?? null,
            ], $radarrClient->searchMovies($term));
        } catch (\Throwable) {
            $errors[] = 'radarr';
        }

        try {
            $jellyseerrClient = new JellyseerrClient(ServiceConnection::resolveActive(ServiceType::Jellyseerr));
            $jellyseerrResponse = $jellyseerrClient->search($term);
            $jellyseerrResults = $jellyseerrResponse['results'] ?? $jellyseerrResponse;
            $results['requests'] = array_map(fn (array $item): array => [
                'id' => $item['id'] ?? null,
                'media_type' => $item['mediaType'] ?? null,
                'title' => $item['title'] ?? ($item['name'] ?? null),
                'overview' => $item['overview'] ?? null,
                'poster_path' => $item['posterPath'] ?? null,
            ], is_array($jellyseerrResults) ? $jellyseerrResults : []);
        } catch (\Throwable) {
            $errors[] = 'jellyseerr';
        }

        return Inertia::render('Search', [
            'query' => $term,
            'results' => $results,
            'errors' => $errors,
        ]);
    }
}
