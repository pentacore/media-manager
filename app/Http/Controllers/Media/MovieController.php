<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Enums\ActionRequestStatus;
use App\Enums\ServiceType;
use App\Http\Requests\Media\StoreMovieRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionDescriber;
use App\Services\Actions\ActionOrchestrator;
use App\Services\Actions\UndescribableAction;
use App\Services\Radarr\RadarrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Override;

class MovieController extends BaseArrController
{
    public function index(): Response|RedirectResponse
    {
        $connection = $this->resolveConnection();
        if ($connection instanceof RedirectResponse) {
            return $connection;
        }

        return Inertia::render('Radarr/Movies/Index', [
            'connection' => $this->connectionUrl($connection),
            'movies' => Inertia::defer(fn (): array => array_map(
                fn (array $item): array => $this->mapMovie($item),
                $this->tryClientCall($connection, fn (RadarrClient $radarrClient): array => $radarrClient->getMovies()),
            )),
            'qualityProfiles' => Inertia::defer(fn (): array => $this->mapQualityProfiles(
                $this->tryClientCall($connection, fn (RadarrClient $radarrClient): array => $radarrClient->getQualityProfiles()),
            )),
        ]);
    }

    public function show(int $id): Response|RedirectResponse
    {
        $connection = $this->resolveConnection();
        if ($connection instanceof RedirectResponse) {
            return $connection;
        }

        try {
            $movie = $this->buildClient($connection)->getMovieById($id);
        } catch (RequestException|ConnectionException) {
            return $this->connectionFailedRedirect();
        }

        return Inertia::render('Radarr/Movies/Show', [
            'connection' => $this->connectionUrl($connection),
            'service_connection_id' => $connection->id,
            'movie' => $this->mapMovie($movie, detailed: true),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $connection = $this->resolveConnection();
        if ($connection instanceof RedirectResponse) {
            return $connection;
        }

        $term = trim((string) $request->query('q', ''));

        return Inertia::render('Radarr/Movies/Create', [
            'connection' => $this->connectionUrl($connection),
            'searchTerm' => $term,
            'qualityProfiles' => Inertia::defer(fn (): array => $this->mapQualityProfiles(
                $this->tryClientCall($connection, fn (RadarrClient $radarrClient): array => $radarrClient->getQualityProfiles()),
            )),
            'rootFolders' => Inertia::defer(fn (): array => array_map(fn (array $f): array => [
                'id' => $f['id'] ?? null,
                'path' => $f['path'] ?? '',
                'free_space' => $f['freeSpace'] ?? null,
            ], $this->tryClientCall($connection, fn (RadarrClient $radarrClient): array => $radarrClient->getRootFolders()))),
            'searchResults' => Inertia::defer(fn (): array => $term === ''
                ? []
                : array_map(fn (array $item): array => [
                    'tmdb_id' => $item['tmdbId'] ?? null,
                    'title' => $item['title'] ?? null,
                    'year' => $item['year'] ?? null,
                    'overview' => $item['overview'] ?? null,
                    'remote_poster' => $item['remotePoster'] ?? null,
                    'images' => $item['images'] ?? [],
                ], $this->tryClientCall($connection, fn (RadarrClient $radarrClient): array => $radarrClient->searchMovies($term)))),
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

    public function destroy(int $id, Request $request, ActionOrchestrator $actionOrchestrator, ActionDescriber $actionDescriber): RedirectResponse
    {
        $connection = $this->resolveConnection();
        if ($connection instanceof RedirectResponse) {
            return $connection;
        }

        $payload = [
            'radarr_movie_id' => $id,
            'delete_files' => $request->boolean('delete_files'),
            'service_connection_id' => $connection->id,
        ];

        try {
            $description = $actionDescriber
                ->describe('delete_movie', $payload)
                ->because(sprintf('Requested from the movie page by %s.', $request->user()->name));
        } catch (UndescribableAction) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to delete movie.')]);

            return back();
        }

        $actionRequest = $actionOrchestrator->dispatch(
            type: 'delete_movie',
            sourceService: 'radarr',
            targetService: 'radarr',
            payload: $payload,
            description: $description,
            origin: 'manual',
        );

        if ($actionRequest === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Deleting movies is disabled in Action Rules.')]);

            return back();
        }

        if ($actionRequest->status === ActionRequestStatus::Pending) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('Deletion queued for approval in the Action Queue.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Movie deletion queued.')]);

        return to_route('media.movies.index');
    }

    protected function serviceType(): ServiceType
    {
        return ServiceType::Radarr;
    }

    protected function buildClient(ServiceConnection $serviceConnection): RadarrClient
    {
        return new RadarrClient($serviceConnection);
    }

    #[Override]
    protected function client(): RadarrClient
    {
        return $this->buildClient(ServiceConnection::resolveActive($this->serviceType()));
    }

    protected function noConnectionMessage(): string
    {
        return __('No active Radarr connection configured.');
    }

    protected function connectionFailedMessage(): string
    {
        return __('Failed to connect to Radarr.');
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
}
