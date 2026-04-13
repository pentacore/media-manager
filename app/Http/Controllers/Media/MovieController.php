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
            $client = $this->client();
            $movies = $client->getMovies();
            $qualityProfiles = $client->getQualityProfiles();
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            return $this->connectionFailedRedirect();
        }

        return Inertia::render('Radarr/Movies/Index', [
            'movies' => array_map(fn (array $item): array => $this->mapMovie($item), $movies),
            'qualityProfiles' => $this->mapQualityProfiles($qualityProfiles),
        ]);
    }

    public function show(int $id): Response|RedirectResponse
    {
        try {
            $movie = $this->client()->getMovieById($id);
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            return $this->connectionFailedRedirect();
        }

        return Inertia::render('Radarr/Movies/Show', [
            'movie' => $this->mapMovie($movie, detailed: true),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        try {
            $client = $this->client();
            $qualityProfiles = $client->getQualityProfiles();
            $rootFolders = $client->getRootFolders();
            $lookup = [];
            $term = trim((string) $request->query('q', ''));
            if ($term !== '') {
                $lookup = $client->searchMovies($term);
            }
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            return $this->connectionFailedRedirect();
        }

        return Inertia::render('Radarr/Movies/Create', [
            'qualityProfiles' => $this->mapQualityProfiles($qualityProfiles),
            'rootFolders' => array_map(fn (array $f): array => [
                'id' => $f['id'] ?? null,
                'path' => $f['path'] ?? '',
                'free_space' => $f['freeSpace'] ?? null,
            ], $rootFolders),
            'searchTerm' => $term,
            'searchResults' => array_map(fn (array $item): array => [
                'tmdb_id' => $item['tmdbId'] ?? null,
                'title' => $item['title'] ?? null,
                'year' => $item['year'] ?? null,
                'overview' => $item['overview'] ?? null,
                'remote_poster' => $item['remotePoster'] ?? null,
                'images' => $item['images'] ?? [],
            ], $lookup),
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
