<?php

declare(strict_types=1);

namespace App\Http\Controllers\Library;

use App\Enums\LibraryBulkAction;
use App\Enums\MediaSearchCommand;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Library\BulkLibraryActionRequest;
use App\Http\Requests\Library\GrabReleaseRequest;
use App\Http\Requests\Library\MonitorEpisodesRequest;
use App\Http\Requests\Library\MonitorMediaRequest;
use App\Http\Requests\Library\MonitorSeasonRequest;
use App\Http\Requests\Library\ReleaseSearchRequest;
use App\Http\Requests\Library\SearchMediaRequest;
use App\Http\Requests\Library\SetQualityProfileRequest;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Services\Actions\BulkItemOutcome;
use App\Services\Actions\BulkRunner;
use App\Services\Actions\ManualActionDispatcher;
use App\Services\Actions\ManualActionOutcome;
use App\Services\Arr\ArrConnections;
use App\Services\Arr\ReleaseSelectionCache;
use App\Services\Library\LibraryActionRequester;
use App\Services\MediaReplacement\PendingReplacementGuard;
use App\Services\Sonarr\SonarrClient;
use App\Services\Sonarr\SonarrEpisodeOwnership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Member library actions from the series, movie, calendar and Wanted pages.
 * Every write goes through ManualActionDispatcher (Action Queue, Action
 * Rules, audit) and pins the connection the page was rendered from.
 */
class MediaActionController extends Controller
{
    public function monitor(MonitorMediaRequest $monitorMediaRequest, LibraryActionRequester $libraryActionRequester): RedirectResponse
    {
        $validated = $monitorMediaRequest->validated();

        return $this->answer($libraryActionRequester->monitor(
            $monitorMediaRequest->connection(),
            (int) $validated['item_id'],
            (bool) $validated['monitored'],
            $this->because($monitorMediaRequest),
        ), __('Monitoring updated.'));
    }

    public function monitorEpisodes(MonitorEpisodesRequest $monitorEpisodesRequest, ManualActionDispatcher $manualActionDispatcher, PendingReplacementGuard $pendingReplacementGuard, SonarrEpisodeOwnership $sonarrEpisodeOwnership): RedirectResponse
    {
        $validated = $monitorEpisodesRequest->validated();
        $connection = $monitorEpisodesRequest->connection();
        $seriesId = (int) $validated['series_id'];
        $episodeIds = array_values(array_map(intval(...), $validated['episode_ids']));
        $seasonNumber = isset($validated['season_number']) ? (int) $validated['season_number'] : null;

        if ($this->replacementInFlight($pendingReplacementGuard, $connection, $seriesId, null)) {
            return $this->refuseDuringReplacement();
        }

        $refusal = $this->episodeOwnershipRefusal($sonarrEpisodeOwnership, $connection, $seriesId, $episodeIds, $seasonNumber);

        if ($refusal !== null) {
            return $this->refuse($refusal['message']);
        }

        $payload = ['series_id' => $seriesId, 'episode_ids' => $episodeIds];

        if ($seasonNumber !== null) {
            $payload['season_number'] = $seasonNumber;
        }

        return $this->answer($manualActionDispatcher->dispatch(
            'monitor_episodes',
            ServiceType::Sonarr,
            [...$payload, 'monitored' => (bool) $validated['monitored'], 'service_connection_id' => $connection->id],
            $this->because($monitorEpisodesRequest),
        ), __('Monitoring updated.'));
    }

    public function monitorSeason(MonitorSeasonRequest $monitorSeasonRequest, ManualActionDispatcher $manualActionDispatcher, PendingReplacementGuard $pendingReplacementGuard): RedirectResponse
    {
        $validated = $monitorSeasonRequest->validated();
        $connection = $monitorSeasonRequest->connection();
        $seriesId = (int) $validated['series_id'];

        if ($this->replacementInFlight($pendingReplacementGuard, $connection, $seriesId, null)) {
            return $this->refuseDuringReplacement();
        }

        return $this->answer($manualActionDispatcher->dispatch(
            'monitor_season',
            ServiceType::Sonarr,
            ['series_id' => $seriesId, 'season_number' => (int) $validated['season_number'], 'service_connection_id' => $connection->id],
            $this->because($monitorSeasonRequest),
        ), __('Monitoring updated.'));
    }

    public function qualityProfile(SetQualityProfileRequest $setQualityProfileRequest, LibraryActionRequester $libraryActionRequester): RedirectResponse
    {
        $validated = $setQualityProfileRequest->validated();

        return $this->answer($libraryActionRequester->setQualityProfile(
            $setQualityProfileRequest->connection(),
            (int) $validated['item_id'],
            (int) $validated['quality_profile_id'],
            $this->because($setQualityProfileRequest),
        ), __('Quality profile updated.'));
    }

    public function search(SearchMediaRequest $searchMediaRequest, ManualActionDispatcher $manualActionDispatcher, LibraryActionRequester $libraryActionRequester, SonarrEpisodeOwnership $sonarrEpisodeOwnership): RedirectResponse
    {
        $validated = $searchMediaRequest->validated();
        $connection = $searchMediaRequest->connection();
        $mediaSearchCommand = MediaSearchCommand::from((string) $validated['command']);

        // The whole-series / whole-movie "Search" button on the title page is
        // the single-title action: route it through LibraryActionRequester,
        // same as monitor() and qualityProfile(), so the title page and the
        // bulk endpoints share one code path. Season, episode and the
        // missing/cutoff-unmet sweeps are not single-title and keep the
        // generic search_media dispatch below.
        $singleTitleItemId = match (true) {
            $mediaSearchCommand === MediaSearchCommand::SeriesSearch => (int) $validated['series_id'],
            $mediaSearchCommand === MediaSearchCommand::MoviesSearch && count($validated['movie_ids'] ?? []) === 1 => (int) $validated['movie_ids'][0],
            default => null,
        };

        if ($singleTitleItemId !== null) {
            return $this->answer($libraryActionRequester->search(
                $connection,
                $singleTitleItemId,
                $this->because($searchMediaRequest),
            ), __('Search started.'));
        }

        $episodeIds = array_values(array_map(intval(...), $validated['episode_ids'] ?? []));

        if ($mediaSearchCommand === MediaSearchCommand::EpisodeSearch) {
            $refusal = $this->episodeOwnershipRefusal(
                $sonarrEpisodeOwnership,
                $connection,
                (int) $validated['series_id'],
                $episodeIds,
                null,
            );

            if ($refusal !== null) {
                return $this->refuse($refusal['message']);
            }
        }

        $payload = ['service' => $mediaSearchCommand->service()->value, 'command' => $mediaSearchCommand->value];

        foreach (['series_id', 'season_number'] as $key) {
            if (isset($validated[$key])) {
                $payload[$key] = (int) $validated[$key];
            }
        }

        if ($episodeIds !== []) {
            $payload['episode_ids'] = $episodeIds;
        }

        if (! empty($validated['movie_ids'])) {
            $payload['movie_ids'] = array_values(array_map(intval(...), $validated['movie_ids']));
        }

        return $this->answer($manualActionDispatcher->dispatch(
            'search_media',
            $mediaSearchCommand->service(),
            [...$payload, 'service_connection_id' => $connection->id],
            $this->because($searchMediaRequest),
        ), __('Search started.'));
    }

    public function releases(ReleaseSearchRequest $releaseSearchRequest, ReleaseSelectionCache $releaseSelectionCache, SonarrEpisodeOwnership $sonarrEpisodeOwnership, ArrConnections $arrConnections): JsonResponse
    {
        $validated = $releaseSearchRequest->validated();
        $connection = $releaseSearchRequest->connection();
        $serviceType = $releaseSearchRequest->serviceType();

        if ($serviceType === ServiceType::Sonarr && isset($validated['episode_id'])) {
            $refusal = $this->episodeOwnershipRefusal($sonarrEpisodeOwnership, $connection, (int) $validated['item_id'], [(int) $validated['episode_id']], null);

            if ($refusal !== null) {
                return response()->json(['message' => $refusal['message']], $refusal['status']);
            }
        }

        $params = match (true) {
            $serviceType === ServiceType::Radarr => ['movieId' => (int) $validated['item_id']],
            isset($validated['episode_id']) => ['episodeId' => (int) $validated['episode_id']],
            default => ['seriesId' => (int) $validated['item_id'], 'seasonNumber' => (int) $validated['season_number']],
        };

        $arrClient = $arrConnections->client($connection);

        try {
            $releases = $arrClient->getReleases($params);
        } catch (RequestException|ConnectionException) {
            return response()->json(['message' => sprintf('%s is unreachable.', ucfirst($serviceType->value))], 502);
        }

        $itemId = (int) $validated['item_id'];
        $seasonNumber = isset($validated['season_number']) ? (int) $validated['season_number'] : null;
        $episodeId = isset($validated['episode_id']) ? (int) $validated['episode_id'] : null;
        $rows = [];

        foreach ($releases as $release) {
            $row = is_array($release) ? $releaseSelectionCache->remember($connection, $release, $itemId, $seasonNumber, $episodeId) : null;

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return response()->json(['releases' => $rows]);
    }

    public function grab(GrabReleaseRequest $grabReleaseRequest, ReleaseSelectionCache $releaseSelectionCache, ManualActionDispatcher $manualActionDispatcher): JsonResponse
    {
        $validated = $grabReleaseRequest->validated();
        $connection = $grabReleaseRequest->connection();
        $serviceType = $grabReleaseRequest->serviceType();
        $itemId = (int) $validated['item_id'];
        $release = $releaseSelectionCache->find($connection, (int) $validated['indexer_id'], (string) $validated['release_key']);

        abort_if($release === null || ($release['target']['item_id'] ?? null) !== $itemId, 422, 'That release is no longer available — run the search again.');

        $releaseFacts = $release;
        unset($releaseFacts['target'], $releaseFacts['guid']);

        $manualActionOutcome = $manualActionDispatcher->dispatch('grab_release', $serviceType, [
            'service' => $serviceType->value,
            $serviceType === ServiceType::Sonarr ? 'series_id' : 'movie_id' => $itemId,
            'guid' => $release['guid'],
            'indexer_id' => $release['indexer_id'],
            'release' => $releaseFacts,
            'service_connection_id' => $connection->id,
        ], sprintf('Picked from interactive search by %s.', $grabReleaseRequest->user()->name));

        abort_unless($manualActionOutcome->dispatched(), 422, $manualActionOutcome->toast('')['message']);

        return response()->json([
            'action_request_id' => $manualActionOutcome->actionRequest?->id,
            'requires_approval' => $manualActionOutcome->state === ManualActionOutcome::QUEUED,
            'message' => $manualActionOutcome->toast(__('Release sent to the download client.'))['message'],
        ], 201);
    }

    public function bulk(BulkLibraryActionRequest $bulkLibraryActionRequest, LibraryActionRequester $libraryActionRequester, BulkRunner $bulkRunner): JsonResponse
    {
        $validated = $bulkLibraryActionRequest->validated();
        $connection = $bulkLibraryActionRequest->connection();
        $libraryBulkAction = LibraryBulkAction::from((string) $validated['action']);
        $ids = $bulkLibraryActionRequest->bulkIds();
        $qualityProfileId = isset($validated['quality_profile_id']) ? (int) $validated['quality_profile_id'] : null;
        $deleteFiles = (bool) ($validated['delete_files'] ?? false);
        $because = sprintf('Requested in bulk from the library by %s.', $bulkLibraryActionRequest->user()->name);
        $titles = $this->indexedTitles($connection, $ids);

        $bulkSummary = $bulkRunner->run(
            $ids,
            fn (int $itemId): BulkItemOutcome => BulkItemOutcome::fromManualAction(
                $libraryActionRequester->apply($libraryBulkAction, $connection, $itemId, $qualityProfileId, $deleteFiles, $because),
            ),
            fn (int $itemId): string => $titles[$itemId] ?? sprintf('#%d', $itemId),
        );

        return response()->json($bulkSummary->withToast());
    }

    /**
     * Failure-line names from the local library index (no upstream call),
     * never from the browser.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function indexedTitles(ServiceConnection $serviceConnection, array $ids): array
    {
        $titled = static fn (string $title, ?int $year): string => $year !== null && $year > 0 ? sprintf('%s (%d)', $title, $year) : $title;

        if ($serviceConnection->type === ServiceType::Sonarr) {
            return IndexedSeries::query()
                ->where('service_connection_id', $serviceConnection->id)
                ->whereIn('sonarr_id', $ids)
                ->get(['sonarr_id', 'title', 'year'])
                ->mapWithKeys(fn (IndexedSeries $indexedSeries): array => [$indexedSeries->sonarr_id => $titled($indexedSeries->title, $indexedSeries->year)])
                ->all();
        }

        return IndexedMovie::query()
            ->where('service_connection_id', $serviceConnection->id)
            ->whereIn('radarr_id', $ids)
            ->get(['radarr_id', 'title', 'year'])
            ->mapWithKeys(fn (IndexedMovie $indexedMovie): array => [$indexedMovie->radarr_id => $titled($indexedMovie->title, $indexedMovie->year)])
            ->all();
    }

    /**
     * Null when every id is an episode of the series (and season); otherwise
     * the refusal to show, with the status a JSON caller answers.
     *
     * @param  list<int>  $episodeIds
     * @return array{message: string, status: int}|null
     */
    private function episodeOwnershipRefusal(SonarrEpisodeOwnership $sonarrEpisodeOwnership, ServiceConnection $serviceConnection, int $seriesId, array $episodeIds, ?int $seasonNumber): ?array
    {
        try {
            if ($sonarrEpisodeOwnership->allBelongTo(new SonarrClient($serviceConnection), $seriesId, $episodeIds, $seasonNumber)) {
                return null;
            }
        } catch (RequestException|ConnectionException) {
            return ['message' => sprintf('%s is unreachable.', ucfirst($serviceConnection->type->value)), 'status' => 502];
        }

        return ['message' => __('Those episodes are not part of this series — refresh and try again.'), 'status' => 422];
    }

    private function refuse(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back();
    }

    private function replacementInFlight(PendingReplacementGuard $pendingReplacementGuard, ServiceConnection $serviceConnection, ?int $seriesId, ?int $movieId): bool
    {
        return $pendingReplacementGuard->inFlightForMedia($serviceConnection->id, seriesId: $seriesId, movieId: $movieId);
    }

    private function refuseDuringReplacement(): RedirectResponse
    {
        return $this->refuse(__('A file replacement is in progress for this title — try again when it finishes.'));
    }

    /**
     * The action reason, naming the page the request came from: the library
     * pages by default, or the Seasonal Anime page when it says so.
     */
    private function because(FormRequest $formRequest): string
    {
        $page = $formRequest->validated('origin') === 'seasonal_anime' ? 'Seasonal Anime' : 'the library';

        return sprintf('Requested from %s by %s.', $page, $formRequest->user()->name);
    }

    private function answer(ManualActionOutcome $manualActionOutcome, string $startedMessage): RedirectResponse
    {
        Inertia::flash('toast', $manualActionOutcome->toast($startedMessage));

        return back();
    }
}
