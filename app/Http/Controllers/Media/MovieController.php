<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\StoreMovieRequest;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MovieController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Radarr);
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        }

        return Inertia::render('Radarr/Movies/Index', [
            'connection' => ['url' => rtrim($connection->url, '/')],
            'movies' => Inertia::defer(function () use ($connection): array {
                try {
                    return array_map(fn (array $item): array => $this->mapMovie($item), new RadarrClient($connection)->getMovies());
                } catch (RequestException|ConnectionException) {
                    return [];
                }
            }),
            'qualityProfiles' => Inertia::defer(function () use ($connection): array {
                try {
                    return $this->mapQualityProfiles(new RadarrClient($connection)->getQualityProfiles());
                } catch (RequestException|ConnectionException) {
                    return [];
                }
            }),
        ]);
    }

    public function show(int $id): Response|RedirectResponse
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Radarr);
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        }

        try {
            $movie = new RadarrClient($connection)->getMovieById($id);
        } catch (RequestException|ConnectionException) {
            return $this->connectionFailedRedirect();
        }

        return Inertia::render('Radarr/Movies/Show', [
            'connection' => ['url' => rtrim($connection->url, '/')],
            'movie' => $this->mapMovie($movie, detailed: true),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Radarr);
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        }

        $term = trim((string) $request->query('q', ''));

        return Inertia::render('Radarr/Movies/Create', [
            'connection' => ['url' => rtrim($connection->url, '/')],
            'searchTerm' => $term,
            'qualityProfiles' => Inertia::defer(function () use ($connection): array {
                try {
                    return $this->mapQualityProfiles(new RadarrClient($connection)->getQualityProfiles());
                } catch (RequestException|ConnectionException) {
                    return [];
                }
            }),
            'rootFolders' => Inertia::defer(function () use ($connection): array {
                try {
                    return array_map(fn (array $f): array => [
                        'id' => $f['id'] ?? null,
                        'path' => $f['path'] ?? '',
                        'free_space' => $f['freeSpace'] ?? null,
                    ], new RadarrClient($connection)->getRootFolders());
                } catch (RequestException|ConnectionException) {
                    return [];
                }
            }),
            'searchResults' => Inertia::defer(function () use ($connection, $term): array {
                if ($term === '') {
                    return [];
                }

                try {
                    return array_map(fn (array $item): array => [
                        'tmdb_id' => $item['tmdbId'] ?? null,
                        'title' => $item['title'] ?? null,
                        'year' => $item['year'] ?? null,
                        'overview' => $item['overview'] ?? null,
                        'remote_poster' => $item['remotePoster'] ?? null,
                        'images' => $item['images'] ?? [],
                    ], new RadarrClient($connection)->searchMovies($term));
                } catch (RequestException|ConnectionException) {
                    return [];
                }
            }),
        ]);
    }

    public function store(StoreMovieRequest $storeMovieRequest): RedirectResponse
    {
        try {
            $this->client()->addMovie($storeMovieRequest->validated());
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to add movie.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Movie added.')]);

        return to_route('media.movies.index');
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        $deleteFiles = $request->boolean('delete_files');

        try {
            $this->client()->deleteMovie($id, $deleteFiles);
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to delete movie.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Movie deleted.')]);

        return to_route('media.movies.index');
    }

    private function client(): RadarrClient
    {
        return new RadarrClient(ServiceConnection::resolveActive(ServiceType::Radarr));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mapMovie(array $item, bool $detailed = false): array
    {
        $base = [
            'id' => $item['id'] ?? null,
            'title' => $item['title'] ?? null,
            'title_slug' => $item['titleSlug'] ?? null,
            'year' => $item['year'] ?? null,
            'status' => $item['status'] ?? null,
            'monitored' => $item['monitored'] ?? false,
            'has_file' => $item['hasFile'] ?? false,
            'quality_profile_id' => $item['qualityProfileId'] ?? null,
            'size_on_disk' => $item['sizeOnDisk'] ?? 0,
            'images' => $item['images'] ?? [],
        ];

        if ($detailed) {
            $base['overview'] = $item['overview'] ?? null;
            $base['runtime'] = $item['runtime'] ?? null;
            $base['studio'] = $item['studio'] ?? null;
            $base['root_folder_path'] = $item['rootFolderPath'] ?? null;
            $base['movie_file'] = isset($item['movieFile']) ? [
                'quality' => $item['movieFile']['quality']['quality']['name'] ?? null,
                'size' => $item['movieFile']['size'] ?? 0,
                'relative_path' => $item['movieFile']['relativePath'] ?? null,
            ] : null;
        }

        return $base;
    }

    /**
     * @param  array<int, array<string, mixed>>  $profiles
     * @return array<int, array<string, mixed>>
     */
    private function mapQualityProfiles(array $profiles): array
    {
        return array_map(fn (array $p): array => [
            'id' => $p['id'] ?? null,
            'name' => $p['name'] ?? null,
        ], $profiles);
    }

    private function noConnectionRedirect(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('No active Radarr connection configured.')]);

        return to_route('dashboard');
    }

    private function connectionFailedRedirect(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to connect to Radarr.')]);

        return to_route('dashboard');
    }
}
