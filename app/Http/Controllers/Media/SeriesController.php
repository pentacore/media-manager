<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Enums\ActionRequestStatus;
use App\Enums\ServiceType;
use App\Http\Requests\Media\StoreSeriesRequest;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionDescriber;
use App\Services\Actions\ActionOrchestrator;
use App\Services\Actions\UndescribableAction;
use App\Services\Sonarr\SonarrClient;
use App\Support\Abilities;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Override;

class SeriesController extends BaseArrController
{
    public function index(Request $request): Response|RedirectResponse
    {
        $connection = $this->resolveConnection();
        if ($connection instanceof RedirectResponse) {
            return $connection;
        }

        $canManageLibrary = $request->user()->can(Abilities::MANAGE_LIBRARY);

        return Inertia::render('Sonarr/Series/Index', [
            'connection' => $canManageLibrary ? $this->connectionUrl($connection) : ['url' => null],
            'series' => Inertia::defer(fn (): array => array_map(
                fn (array $item): array => $this->mapSeries($item),
                $this->tryClientCall($connection, fn (SonarrClient $sonarrClient): array => $sonarrClient->getSeries()),
            )),
            'qualityProfiles' => Inertia::defer(fn (): array => $this->mapQualityProfiles(
                $this->tryClientCall($connection, fn (SonarrClient $sonarrClient): array => $sonarrClient->getQualityProfiles()),
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
            $series = $this->buildClient($connection)->getSeriesById($id);
        } catch (RequestException|ConnectionException) {
            return $this->connectionFailedRedirect();
        }

        $canManageLibrary = $request->user()->can(Abilities::MANAGE_LIBRARY);

        return Inertia::render('Sonarr/Series/Show', [
            'connection' => $canManageLibrary ? $this->connectionUrl($connection) : ['url' => null],
            'service_connection_id' => $connection->id,
            'series' => $this->mapSeries($series, detailed: true, canManageLibrary: $canManageLibrary),
            'episodes' => Inertia::defer(fn (): array => array_map(fn (array $ep): array => [
                'id' => $ep['id'] ?? null,
                'season_number' => $ep['seasonNumber'] ?? 0,
                'episode_number' => $ep['episodeNumber'] ?? 0,
                'title' => $ep['title'] ?? null,
                'air_date' => $ep['airDate'] ?? null,
                'has_file' => $ep['hasFile'] ?? false,
                'monitored' => $ep['monitored'] ?? false,
                'overview' => $ep['overview'] ?? null,
            ], $this->tryClientCall($connection, fn (SonarrClient $sonarrClient): array => $sonarrClient->getEpisodesBySeries($id)))),
            // Only the profile dropdown needs these, and only manage-library
            // users get the dropdown — viewers trigger no upstream lookup.
            ...$canManageLibrary ? [
                'qualityProfiles' => Inertia::defer(fn (): array => $this->mapQualityProfiles(
                    $this->tryClientCall($connection, fn (SonarrClient $sonarrClient): array => $sonarrClient->getQualityProfiles()),
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

        return Inertia::render('Sonarr/Series/Create', [
            'connection' => $this->connectionUrl($connection),
            'searchTerm' => $term,
            'qualityProfiles' => Inertia::defer(fn (): array => $this->mapQualityProfiles(
                $this->tryClientCall($connection, fn (SonarrClient $sonarrClient): array => $sonarrClient->getQualityProfiles()),
            )),
            'rootFolders' => Inertia::defer(fn (): array => array_map(fn (array $f): array => [
                'id' => $f['id'] ?? null,
                'path' => $f['path'] ?? '',
                'free_space' => $f['freeSpace'] ?? null,
            ], $this->tryClientCall($connection, fn (SonarrClient $sonarrClient): array => $sonarrClient->getRootFolders()))),
            'searchResults' => Inertia::defer(fn (): array => $term === ''
                ? []
                : array_map(fn (array $item): array => [
                    'tvdb_id' => $item['tvdbId'] ?? null,
                    'title' => $item['title'] ?? null,
                    'year' => $item['year'] ?? null,
                    'overview' => $item['overview'] ?? null,
                    'remote_poster' => $item['remotePoster'] ?? null,
                    'images' => $item['images'] ?? [],
                ], $this->tryClientCall($connection, fn (SonarrClient $sonarrClient): array => $sonarrClient->searchSeries($term)))),
        ]);
    }

    public function store(StoreSeriesRequest $storeSeriesRequest): RedirectResponse
    {
        try {
            $this->client()->addSeries($storeSeriesRequest->validated());
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to add series.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Series added.')]);

        return to_route('media.series.index');
    }

    public function destroy(int $id, Request $request, ActionOrchestrator $actionOrchestrator, ActionDescriber $actionDescriber): RedirectResponse
    {
        $connection = $this->resolveConnection();
        if ($connection instanceof RedirectResponse) {
            return $connection;
        }

        $payload = [
            'sonarr_series_id' => $id,
            'delete_files' => $request->boolean('delete_files'),
            'service_connection_id' => $connection->id,
        ];

        try {
            $description = $actionDescriber
                ->describe('delete_series', $payload)
                ->because(sprintf('Requested from the series page by %s.', $request->user()->name));
        } catch (UndescribableAction) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to delete series.')]);

            return back();
        }

        $actionRequest = $actionOrchestrator->dispatch(
            type: 'delete_series',
            sourceService: 'sonarr',
            targetService: 'sonarr',
            payload: $payload,
            description: $description,
            origin: 'manual',
        );

        if (! $actionRequest instanceof ActionRequest) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Deleting series is disabled in Action Rules.')]);

            return back();
        }

        if ($actionRequest->status === ActionRequestStatus::Pending) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('Deletion queued for approval in the Action Queue.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Series deletion queued.')]);

        return to_route('media.series.index');
    }

    protected function serviceType(): ServiceType
    {
        return ServiceType::Sonarr;
    }

    protected function buildClient(ServiceConnection $serviceConnection): SonarrClient
    {
        return new SonarrClient($serviceConnection);
    }

    #[Override]
    protected function client(): SonarrClient
    {
        return $this->buildClient(ServiceConnection::resolveActive($this->serviceType()));
    }

    protected function noConnectionMessage(): string
    {
        return __('No active Sonarr connection configured.');
    }

    protected function connectionFailedMessage(): string
    {
        return __('Failed to connect to Sonarr.');
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mapSeries(array $item, bool $detailed = false, bool $canManageLibrary = true): array
    {
        $base = [
            'id' => $item['id'] ?? null,
            'title' => $item['title'] ?? null,
            'title_slug' => $item['titleSlug'] ?? null,
            'year' => $item['year'] ?? null,
            'status' => $item['status'] ?? null,
            'monitored' => $item['monitored'] ?? false,
            'quality_profile_id' => $item['qualityProfileId'] ?? null,
            'season_count' => count($item['seasons'] ?? []),
            'size_on_disk' => $item['statistics']['sizeOnDisk'] ?? 0,
            'episode_file_count' => $item['statistics']['episodeFileCount'] ?? 0,
            'episode_count' => $item['statistics']['episodeCount'] ?? 0,
            'images' => $item['images'] ?? [],
        ];

        if ($detailed) {
            $base['overview'] = $item['overview'] ?? null;
            $base['network'] = $item['network'] ?? null;
            $base['runtime'] = $item['runtime'] ?? null;

            if ($canManageLibrary) {
                $base['root_folder_path'] = $item['rootFolderPath'] ?? null;
            }

            $base['seasons'] = array_map(fn (array $s): array => [
                'season_number' => $s['seasonNumber'] ?? 0,
                'monitored' => $s['monitored'] ?? false,
                'episode_count' => $s['statistics']['episodeCount'] ?? 0,
                'episode_file_count' => $s['statistics']['episodeFileCount'] ?? 0,
                'size_on_disk' => $s['statistics']['sizeOnDisk'] ?? 0,
            ], $item['seasons'] ?? []);
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
