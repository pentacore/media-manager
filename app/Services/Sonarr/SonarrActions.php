<?php

declare(strict_types=1);

namespace App\Services\Sonarr;

use App\Cache\Services\SonarrCache;
use App\Enums\MediaSearchCommand;
use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionExecutor;
use App\Services\Arr\ReleaseGrabber;
use App\Services\Arr\SearchCommandRunner;
use App\Services\MediaReplacement\PendingReplacementGuard;
use App\Services\MediaReplacement\ReplacementInFlight;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class SonarrActions implements ActionExecutor
{
    // Defaults keep `new SonarrActions` (used throughout the tests) working;
    // the container still injects when resolving.
    public function __construct(
        private readonly PendingReplacementGuard $pendingReplacementGuard = new PendingReplacementGuard,
        private readonly ReleaseGrabber $releaseGrabber = new ReleaseGrabber,
        private readonly SearchCommandRunner $searchCommandRunner = new SearchCommandRunner,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(ActionRequest $actionRequest): array
    {
        return match ($actionRequest->type) {
            'delete_series' => $this->deleteSeries($actionRequest),
            'add_series' => $this->addSeries($actionRequest),
            'monitor_series' => $this->monitorSeries($actionRequest),
            'set_series_quality_profile' => $this->setSeriesQualityProfile($actionRequest),
            'monitor_episodes' => $this->monitorEpisodes($actionRequest),
            'search_media' => $this->searchMedia($actionRequest),
            'grab_release' => $this->grabRelease($actionRequest),
            default => throw new InvalidArgumentException(sprintf('SonarrActions cannot execute type "%s"', $actionRequest->type)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteSeries(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $seriesId = (int) ($payload['sonarr_series_id'] ?? 0);

        throw_if($seriesId <= 0, InvalidArgumentException::class, 'sonarr_series_id is required');

        $deleteFiles = (bool) ($payload['delete_files'] ?? false);

        $serviceConnection = ServiceConnection::resolvePinned($payload, ServiceType::Sonarr);
        new SonarrClient($serviceConnection)->deleteSeries($seriesId, $deleteFiles);
        new SonarrCache($serviceConnection)->bustAll();

        return [
            'sonarr_series_id' => $seriesId,
            'delete_files' => $deleteFiles,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function addSeries(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $tvdbId = (int) ($payload['tvdb_id'] ?? 0);

        throw_if($tvdbId <= 0, InvalidArgumentException::class, 'tvdb_id is required');

        $serviceConnection = ServiceConnection::resolvePinned($payload, ServiceType::Sonarr);
        $sonarrClient = new SonarrClient($serviceConnection);

        // Look up the full series spec by tvdb_id (Sonarr's lookup accepts "tvdb:{id}" syntax).
        $candidates = $sonarrClient->searchSeries(sprintf('tvdb:%d', $tvdbId));

        throw_if($candidates === [], InvalidArgumentException::class, sprintf('No series found in Sonarr lookup for tvdb_id %d', $tvdbId));

        $seed = $candidates[0];

        $series = $sonarrClient->addSeries(array_merge($seed, [
            'qualityProfileId' => (int) ($payload['quality_profile_id'] ?? 0),
            'rootFolderPath' => (string) ($payload['root_folder_path'] ?? ''),
            'monitored' => (bool) ($payload['monitored'] ?? true),
            'seasonFolder' => (bool) ($payload['season_folder'] ?? true),
            'addOptions' => ['searchForMissingEpisodes' => true],
        ]));

        new SonarrCache($serviceConnection)->bustAll();

        return [
            'sonarr_series_id' => $series['id'] ?? null,
            'title' => $series['title'] ?? null,
            'tvdb_id' => $tvdbId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function monitorSeries(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $seriesId = (int) ($payload['series_id'] ?? 0);

        throw_if($seriesId <= 0, InvalidArgumentException::class, 'series_id is required');

        $monitored = (bool) ($payload['monitored'] ?? true);

        $serviceConnection = ServiceConnection::resolvePinned($payload, ServiceType::Sonarr);

        throw_if($this->pendingReplacementGuard->inFlightForMedia($serviceConnection->id, seriesId: $seriesId), ReplacementInFlight::forTitle());

        $sonarrClient = new SonarrClient($serviceConnection);
        $series = $sonarrClient->getSeriesById($seriesId);
        $series['monitored'] = $monitored;
        $sonarrClient->updateSeries($seriesId, $series);
        new SonarrCache($serviceConnection)->bustAll();

        return [
            'sonarr_series_id' => $seriesId,
            'monitored' => $monitored,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function setSeriesQualityProfile(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $seriesId = (int) ($payload['series_id'] ?? 0);
        $qualityProfileId = (int) ($payload['quality_profile_id'] ?? 0);

        throw_if($seriesId <= 0, InvalidArgumentException::class, 'series_id is required');
        throw_if($qualityProfileId <= 0, InvalidArgumentException::class, 'quality_profile_id is required');

        $serviceConnection = ServiceConnection::resolvePinned($payload, ServiceType::Sonarr);
        $sonarrClient = new SonarrClient($serviceConnection);
        $series = $sonarrClient->getSeriesById($seriesId);
        $series['qualityProfileId'] = $qualityProfileId;
        $sonarrClient->updateSeries($seriesId, $series);
        new SonarrCache($serviceConnection)->bustAll();

        return [
            'sonarr_series_id' => $seriesId,
            'quality_profile_id' => $qualityProfileId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function monitorEpisodes(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $seriesId = (int) ($payload['series_id'] ?? 0);
        $episodeIds = array_values(array_unique(array_filter(
            array_map(intval(...), (array) ($payload['episode_ids'] ?? [])),
            static fn (int $id): bool => $id > 0,
        )));

        throw_if($seriesId <= 0, InvalidArgumentException::class, 'series_id is required');
        throw_if($episodeIds === [], InvalidArgumentException::class, 'episode_ids is required');

        $monitored = (bool) ($payload['monitored'] ?? true);
        $serviceConnection = ServiceConnection::resolvePinnedStrict($payload, ServiceType::Sonarr);

        throw_if($this->pendingReplacementGuard->inFlightForMedia($serviceConnection->id, seriesId: $seriesId), ReplacementInFlight::forTitle());

        new SonarrClient($serviceConnection)->setEpisodesMonitored($episodeIds, $monitored);
        new SonarrCache($serviceConnection)->bustAll();

        return [
            'sonarr_series_id' => $seriesId,
            'episode_ids' => $episodeIds,
            'monitored' => $monitored,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function searchMedia(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $command = MediaSearchCommand::tryFrom((string) ($payload['command'] ?? ''));

        throw_unless($command instanceof MediaSearchCommand && $command->service() === ServiceType::Sonarr, InvalidArgumentException::class, 'command is not a Sonarr search');

        $serviceConnection = ServiceConnection::resolvePinnedStrict($payload, ServiceType::Sonarr);
        $response = $this->searchCommandRunner->run(new SonarrClient($serviceConnection), 'Sonarr', $command, $command->arrParameters($payload));

        return [
            'command' => $command->value,
            'arr_command_id' => $response['id'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function grabRelease(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $guid = (string) ($payload['guid'] ?? '');
        $indexerId = (int) ($payload['indexer_id'] ?? 0);

        throw_if($guid === '' || $indexerId <= 0, InvalidArgumentException::class, 'guid and indexer_id are required');

        $serviceConnection = ServiceConnection::resolvePinnedStrict($payload, ServiceType::Sonarr);
        $this->releaseGrabber->grab(new SonarrClient($serviceConnection), 'Sonarr', $guid, $indexerId);

        try {
            new SonarrCache($serviceConnection)->bustAll();
        } catch (Throwable $throwable) {
            // The grab already succeeded upstream — a stale cache is a
            // read-freshness problem, not a reason to report the grab as
            // failed (which would leave the member thinking nothing happened).
            Log::warning('SonarrActions: failed to bust the Sonarr cache after a successful grab', [
                'service_connection_id' => $serviceConnection->id,
                'guid' => $guid,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }

        return [
            'indexer_id' => $indexerId,
            'title' => is_string($payload['release']['title'] ?? null) ? $payload['release']['title'] : null,
        ];
    }
}
