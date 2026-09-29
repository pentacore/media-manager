<?php

declare(strict_types=1);

namespace App\Http\Controllers\Library;

use App\Enums\MediaSearchCommand;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Library\GrabReleaseRequest;
use App\Http\Requests\Library\MonitorEpisodesRequest;
use App\Http\Requests\Library\MonitorMediaRequest;
use App\Http\Requests\Library\ReleaseSearchRequest;
use App\Http\Requests\Library\SearchMediaRequest;
use App\Http\Requests\Library\SetQualityProfileRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ManualActionDispatcher;
use App\Services\Actions\ManualActionOutcome;
use App\Services\Arr\ReleaseSelectionCache;
use App\Services\MediaReplacement\PendingReplacementGuard;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Member library actions from the series, movie, calendar and Wanted pages.
 * Every write goes through ManualActionDispatcher (Action Queue, Action
 * Rules, audit) and pins the connection the page was rendered from.
 */
class MediaActionController extends Controller
{
    public function monitor(MonitorMediaRequest $monitorMediaRequest, ManualActionDispatcher $manualActionDispatcher, PendingReplacementGuard $pendingReplacementGuard): RedirectResponse
    {
        $validated = $monitorMediaRequest->validated();
        $connection = $monitorMediaRequest->connection();
        $serviceType = $monitorMediaRequest->serviceType();
        $itemId = (int) $validated['item_id'];
        $isSonarr = $serviceType === ServiceType::Sonarr;

        if ($this->replacementInFlight($pendingReplacementGuard, $connection, $isSonarr ? $itemId : null, $isSonarr ? null : $itemId)) {
            return $this->refuseDuringReplacement();
        }

        return $this->answer($manualActionDispatcher->dispatch(
            $isSonarr ? 'monitor_series' : 'monitor_movie',
            $serviceType,
            [$isSonarr ? 'series_id' : 'movie_id' => $itemId, 'monitored' => (bool) $validated['monitored'], 'service_connection_id' => $connection->id],
            $this->because($monitorMediaRequest),
        ), __('Monitoring updated.'));
    }

    public function monitorEpisodes(MonitorEpisodesRequest $monitorEpisodesRequest, ManualActionDispatcher $manualActionDispatcher, PendingReplacementGuard $pendingReplacementGuard): RedirectResponse
    {
        $validated = $monitorEpisodesRequest->validated();
        $connection = $monitorEpisodesRequest->connection();
        $seriesId = (int) $validated['series_id'];

        if ($this->replacementInFlight($pendingReplacementGuard, $connection, $seriesId, null)) {
            return $this->refuseDuringReplacement();
        }

        $payload = [
            'series_id' => $seriesId,
            'episode_ids' => array_values(array_map(intval(...), $validated['episode_ids'])),
        ];

        if (isset($validated['season_number'])) {
            $payload['season_number'] = (int) $validated['season_number'];
        }

        return $this->answer($manualActionDispatcher->dispatch(
            'monitor_episodes',
            ServiceType::Sonarr,
            [...$payload, 'monitored' => (bool) $validated['monitored'], 'service_connection_id' => $connection->id],
            $this->because($monitorEpisodesRequest),
        ), __('Monitoring updated.'));
    }

    public function qualityProfile(SetQualityProfileRequest $setQualityProfileRequest, ManualActionDispatcher $manualActionDispatcher): RedirectResponse
    {
        $validated = $setQualityProfileRequest->validated();
        $connection = $setQualityProfileRequest->connection();
        $serviceType = $setQualityProfileRequest->serviceType();
        $isSonarr = $serviceType === ServiceType::Sonarr;

        return $this->answer($manualActionDispatcher->dispatch(
            $isSonarr ? 'set_series_quality_profile' : 'set_movie_quality_profile',
            $serviceType,
            [
                $isSonarr ? 'series_id' : 'movie_id' => (int) $validated['item_id'],
                'quality_profile_id' => (int) $validated['quality_profile_id'],
                'service_connection_id' => $connection->id,
            ],
            $this->because($setQualityProfileRequest),
        ), __('Quality profile updated.'));
    }

    public function search(SearchMediaRequest $searchMediaRequest, ManualActionDispatcher $manualActionDispatcher): RedirectResponse
    {
        $validated = $searchMediaRequest->validated();
        $connection = $searchMediaRequest->connection();
        $mediaSearchCommand = MediaSearchCommand::from((string) $validated['command']);

        $payload = ['service' => $mediaSearchCommand->service()->value, 'command' => $mediaSearchCommand->value];

        foreach (['series_id', 'season_number'] as $key) {
            if (isset($validated[$key])) {
                $payload[$key] = (int) $validated[$key];
            }
        }

        foreach (['episode_ids', 'movie_ids'] as $key) {
            if (! empty($validated[$key])) {
                $payload[$key] = array_values(array_map(intval(...), $validated[$key]));
            }
        }

        return $this->answer($manualActionDispatcher->dispatch(
            'search_media',
            $mediaSearchCommand->service(),
            [...$payload, 'service_connection_id' => $connection->id],
            $this->because($searchMediaRequest),
        ), __('Search started.'));
    }

    public function releases(ReleaseSearchRequest $releaseSearchRequest, ReleaseSelectionCache $releaseSelectionCache): JsonResponse
    {
        $validated = $releaseSearchRequest->validated();
        $connection = $releaseSearchRequest->connection();
        $serviceType = $releaseSearchRequest->serviceType();

        $params = match (true) {
            $serviceType === ServiceType::Radarr => ['movieId' => (int) $validated['item_id']],
            isset($validated['episode_id']) => ['episodeId' => (int) $validated['episode_id']],
            default => ['seriesId' => (int) $validated['item_id'], 'seasonNumber' => (int) $validated['season_number']],
        };

        $arrClient = $serviceType === ServiceType::Sonarr ? new SonarrClient($connection) : new RadarrClient($connection);

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
        unset($releaseFacts['target']);

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

    private function replacementInFlight(PendingReplacementGuard $pendingReplacementGuard, ServiceConnection $serviceConnection, ?int $seriesId, ?int $movieId): bool
    {
        return $pendingReplacementGuard->inFlightForMedia($serviceConnection->id, seriesId: $seriesId, movieId: $movieId);
    }

    private function refuseDuringReplacement(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('A file replacement is in progress for this title — try again when it finishes.')]);

        return back();
    }

    private function because(Request $request): string
    {
        return sprintf('Requested from the library by %s.', $request->user()->name);
    }

    private function answer(ManualActionOutcome $manualActionOutcome, string $startedMessage): RedirectResponse
    {
        Inertia::flash('toast', $manualActionOutcome->toast($startedMessage));

        return back();
    }
}
