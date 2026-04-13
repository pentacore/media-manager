<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\StoreSeriesRequest;
use App\Models\ServiceConnection;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SeriesController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        try {
            $client = $this->client();
            $series = $client->getSeries();
            $qualityProfiles = $client->getQualityProfiles();
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            return $this->connectionFailedRedirect();
        }

        return Inertia::render('Sonarr/Series/Index', [
            'series' => array_map(fn (array $item): array => $this->mapSeries($item), $series),
            'qualityProfiles' => $this->mapQualityProfiles($qualityProfiles),
        ]);
    }

    public function show(int $id): Response|RedirectResponse
    {
        try {
            $client = $this->client();
            $series = $client->getSeriesById($id);
            $episodes = $client->getEpisodesBySeries($id);
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            return $this->connectionFailedRedirect();
        }

        return Inertia::render('Sonarr/Series/Show', [
            'series' => $this->mapSeries($series, detailed: true),
            'episodes' => array_map(fn (array $ep): array => [
                'id' => $ep['id'] ?? null,
                'season_number' => $ep['seasonNumber'] ?? 0,
                'episode_number' => $ep['episodeNumber'] ?? 0,
                'title' => $ep['title'] ?? null,
                'air_date' => $ep['airDate'] ?? null,
                'has_file' => $ep['hasFile'] ?? false,
                'monitored' => $ep['monitored'] ?? false,
                'overview' => $ep['overview'] ?? null,
            ], $episodes),
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
                $lookup = $client->searchSeries($term);
            }
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            return $this->connectionFailedRedirect();
        }

        return Inertia::render('Sonarr/Series/Create', [
            'qualityProfiles' => $this->mapQualityProfiles($qualityProfiles),
            'rootFolders' => array_map(fn (array $f): array => [
                'id' => $f['id'] ?? null,
                'path' => $f['path'] ?? '',
                'free_space' => $f['freeSpace'] ?? null,
            ], $rootFolders),
            'searchTerm' => $term,
            'searchResults' => array_map(fn (array $item): array => [
                'tvdb_id' => $item['tvdbId'] ?? null,
                'title' => $item['title'] ?? null,
                'year' => $item['year'] ?? null,
                'overview' => $item['overview'] ?? null,
                'remote_poster' => $item['remotePoster'] ?? null,
                'images' => $item['images'] ?? [],
            ], $lookup),
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

    public function destroy(int $id, Request $request): RedirectResponse
    {
        $deleteFiles = $request->boolean('delete_files');

        try {
            $this->client()->deleteSeries($id, $deleteFiles);
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to delete series.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Series deleted.')]);

        return to_route('media.series.index');
    }

    private function client(): SonarrClient
    {
        return new SonarrClient(ServiceConnection::resolveActive(ServiceType::Sonarr));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mapSeries(array $item, bool $detailed = false): array
    {
        $base = [
            'id' => $item['id'] ?? null,
            'title' => $item['title'] ?? null,
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
            $base['root_folder_path'] = $item['rootFolderPath'] ?? null;
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

    private function noConnectionRedirect(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('No active Sonarr connection configured.')]);

        return to_route('dashboard');
    }

    private function connectionFailedRedirect(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to connect to Sonarr.')]);

        return to_route('dashboard');
    }
}
