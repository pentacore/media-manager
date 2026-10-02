<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Enums\ServiceType;
use App\Http\Requests\Media\StoreMovieRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ManualActionOutcome;
use App\Services\Library\LibraryActionRequester;
use App\Services\Radarr\RadarrClient;
use App\Support\Abilities;
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
    public function index(Request $request): Response|RedirectResponse
    {
        $connection = $this->resolveConnection();
        if ($connection instanceof RedirectResponse) {
            return $connection;
        }

        $canManageLibrary = $request->user()->can(Abilities::MANAGE_LIBRARY);

        return Inertia::render('Radarr/Movies/Index', [
            'connection' => $canManageLibrary ? $this->connectionUrl($connection) : ['url' => null],
            'service_connection_id' => $canManageLibrary ? $connection->id : null,
            'movies' => Inertia::defer(fn (): array => $this->tryClientList(
                $connection,
                fn (RadarrClient $radarrClient): array => $radarrClient->getMovies(),
                fn (array $item): array => $this->mapMovie($item),
            )),
            'qualityProfiles' => Inertia::defer(fn (): array => $this->tryClientList(
                $connection,
                fn (RadarrClient $radarrClient): array => $radarrClient->getQualityProfiles(),
                $this->mapQualityProfile(...),
            )),
        ]);
    }

    public function show(int $id, Request $request): Response|RedirectResponse
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

        $canManageLibrary = $request->user()->can(Abilities::MANAGE_LIBRARY);

        return Inertia::render('Radarr/Movies/Show', [
            'connection' => $canManageLibrary ? $this->connectionUrl($connection) : ['url' => null],
            'service_connection_id' => $connection->id,
            'movie' => $this->mapMovie($movie, detailed: true, canManageLibrary: $canManageLibrary),
            // Only the profile dropdown needs these, and only manage-library
            // users get the dropdown — viewers trigger no upstream lookup.
            ...$canManageLibrary ? [
                'qualityProfiles' => Inertia::defer(fn (): array => $this->tryClientList(
                    $connection,
                    fn (RadarrClient $radarrClient): array => $radarrClient->getQualityProfiles(),
                    $this->mapQualityProfile(...),
                ), 'qualityProfiles'),
            ] : [],
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
            'qualityProfiles' => Inertia::defer(fn (): array => $this->tryClientList(
                $connection,
                fn (RadarrClient $radarrClient): array => $radarrClient->getQualityProfiles(),
                $this->mapQualityProfile(...),
            )),
            'rootFolders' => Inertia::defer(fn (): array => $this->tryClientList(
                $connection,
                fn (RadarrClient $radarrClient): array => $radarrClient->getRootFolders(),
                fn (array $f): array => [
                    'id' => $f['id'] ?? null,
                    'path' => $f['path'] ?? '',
                    'free_space' => $f['freeSpace'] ?? null,
                ],
            )),
            'searchResults' => Inertia::defer(fn (): array => $term === ''
                ? ['items' => [], 'error' => null]
                : $this->tryClientList(
                    $connection,
                    fn (RadarrClient $radarrClient): array => $radarrClient->searchMovies($term),
                    fn (array $item): array => [
                        'tmdb_id' => $item['tmdbId'] ?? null,
                        'title' => $item['title'] ?? null,
                        'year' => $item['year'] ?? null,
                        'overview' => $item['overview'] ?? null,
                        'remote_poster' => $item['remotePoster'] ?? null,
                        'images' => $item['images'] ?? [],
                    ],
                )),
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

    public function destroy(int $id, Request $request, LibraryActionRequester $libraryActionRequester): RedirectResponse
    {
        $connection = $this->resolveConnection();
        if ($connection instanceof RedirectResponse) {
            return $connection;
        }

        $manualActionOutcome = $libraryActionRequester->delete(
            $connection,
            $id,
            $request->boolean('delete_files'),
            sprintf('Requested from the movie page by %s.', $request->user()->name),
        );

        return match ($manualActionOutcome->state) {
            ManualActionOutcome::STARTED => $this->flashAnd('success', __('Movie deletion queued.'), to_route('media.movies.index')),
            ManualActionOutcome::QUEUED => $this->flashAnd('info', __('Deletion queued for approval in the Action Queue.'), back()),
            ManualActionOutcome::DISABLED => $this->flashAnd('error', __('Deleting movies is disabled in Action Rules.'), back()),
            default => $this->flashAnd('error', __('Failed to delete movie.'), back()),
        };
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
    private function mapMovie(array $item, bool $detailed = false, bool $canManageLibrary = true): array
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

            if ($canManageLibrary) {
                $base['root_folder_path'] = $item['rootFolderPath'] ?? null;
            }

            if (isset($item['movieFile'])) {
                $movieFile = [
                    'quality' => $item['movieFile']['quality']['quality']['name'] ?? null,
                    'size' => $item['movieFile']['size'] ?? 0,
                ];

                if ($canManageLibrary) {
                    $movieFile['relative_path'] = $item['movieFile']['relativePath'] ?? null;
                }

                $base['movie_file'] = $movieFile;
            } else {
                $base['movie_file'] = null;
            }
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{id: mixed, name: mixed}
     */
    private function mapQualityProfile(array $profile): array
    {
        return [
            'id' => $profile['id'] ?? null,
            'name' => $profile['name'] ?? null,
        ];
    }
}
