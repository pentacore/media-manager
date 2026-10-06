<?php

declare(strict_types=1);

namespace App\Services\Sonarr;

use App\Cache\Services\SonarrCache;
use App\Enums\MediaSearchCommand;
use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Arr\ArrClient;
use App\Services\Arr\ArrLibraryActions;
use App\Services\Arr\ReleaseGrabber;
use App\Services\Arr\SearchCommandRunner;
use App\Services\MediaReplacement\PendingReplacementGuard;
use App\Services\MediaReplacement\ReplacementInFlight;
use App\Support\PayloadInt;
use InvalidArgumentException;

/**
 * @extends ArrLibraryActions<SonarrClient>
 */
class SonarrActions extends ArrLibraryActions
{
    /**
     * Defaults keep `new SonarrActions` (used throughout the tests) working;
     * the container still injects when resolving.
     */
    public function __construct(
        PendingReplacementGuard $pendingReplacementGuard = new PendingReplacementGuard,
        ReleaseGrabber $releaseGrabber = new ReleaseGrabber,
        SearchCommandRunner $searchCommandRunner = new SearchCommandRunner,
        private readonly SonarrEpisodeOwnership $sonarrEpisodeOwnership = new SonarrEpisodeOwnership,
    ) {
        parent::__construct($pendingReplacementGuard, $releaseGrabber, $searchCommandRunner);
    }

    protected function serviceType(): ServiceType
    {
        return ServiceType::Sonarr;
    }

    protected function itemNoun(): string
    {
        return 'series';
    }

    protected function libraryIdKey(): string
    {
        return 'sonarr_series_id';
    }

    protected function itemIdKey(): string
    {
        return 'series_id';
    }

    protected function externalIdKey(): string
    {
        return 'tvdb_id';
    }

    protected function lookupTerm(int $externalId): string
    {
        return sprintf('tvdb:%d', $externalId);
    }

    protected function client(ServiceConnection $serviceConnection): SonarrClient
    {
        return new SonarrClient($serviceConnection);
    }

    protected function cache(ServiceConnection $serviceConnection): SonarrCache
    {
        return new SonarrCache($serviceConnection);
    }

    /**
     * @param  SonarrClient  $client
     * @return array<int, array<string, mixed>>
     */
    protected function lookup(ArrClient $client, string $term): array
    {
        return $client->searchSeries($term);
    }

    /**
     * @param  SonarrClient  $client
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function add(ArrClient $client, array $item): array
    {
        return $client->addSeries($item);
    }

    /**
     * @param  SonarrClient  $client
     * @return array<string, mixed>
     */
    protected function fetchFresh(ArrClient $client, int $itemId): array
    {
        return $client->fetchSeriesById($itemId);
    }

    /**
     * @param  SonarrClient  $client
     * @param  array<string, mixed>  $item
     */
    protected function update(ArrClient $client, int $itemId, array $item): void
    {
        $client->updateSeries($itemId, $item);
    }

    /**
     * @param  SonarrClient  $client
     */
    protected function delete(ArrClient $client, int $itemId, bool $deleteFiles): void
    {
        $client->deleteSeries($itemId, $deleteFiles);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function addOptions(array $payload): array
    {
        return [
            'seasonFolder' => (bool) ($payload['season_folder'] ?? true),
            'addOptions' => ['searchForMissingEpisodes' => true],
        ];
    }

    protected function replacementInFlight(int $serviceConnectionId, int $itemId): bool
    {
        return $this->pendingReplacementGuard->inFlightForMedia($serviceConnectionId, seriesId: $itemId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function executeOther(ActionRequest $actionRequest): array
    {
        return match ($actionRequest->type) {
            'monitor_episodes' => $this->monitorEpisodes($actionRequest->payload),
            'monitor_season' => $this->monitorSeason($actionRequest->payload),
            default => parent::executeOther($actionRequest),
        };
    }

    /**
     * An episode search's ids must really be that series' episodes.
     *
     * @param  SonarrClient  $client
     * @param  array<string, mixed>  $payload
     */
    protected function assertSearchTargets(ArrClient $client, MediaSearchCommand $mediaSearchCommand, array $payload): void
    {
        if ($mediaSearchCommand !== MediaSearchCommand::EpisodeSearch) {
            return;
        }

        $seriesId = (int) ($payload['series_id'] ?? 0);
        $episodeIds = $this->episodeIds($payload);

        throw_if($seriesId <= 0 || $episodeIds === [], InvalidArgumentException::class, 'series_id and episode_ids are required for an episode search');
        throw_unless(
            $this->sonarrEpisodeOwnership->allBelongTo($client, $seriesId, $episodeIds),
            InvalidArgumentException::class,
            sprintf('episode_ids are not all episodes of series %d', $seriesId),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function monitorEpisodes(array $payload): array
    {
        $seriesId = PayloadInt::required($payload, 'series_id');
        $episodeIds = $this->episodeIds($payload);
        $seasonNumber = isset($payload['season_number']) ? (int) $payload['season_number'] : null;

        throw_if($episodeIds === [], InvalidArgumentException::class, 'episode_ids is required');

        $monitored = (bool) ($payload['monitored'] ?? true);
        $serviceConnection = ServiceConnection::resolvePinnedStrict($payload, ServiceType::Sonarr);

        throw_if($this->pendingReplacementGuard->inFlightForMedia($serviceConnection->id, seriesId: $seriesId), ReplacementInFlight::forTitle());

        $sonarrClient = new SonarrClient($serviceConnection);

        // The guard above and the Action Queue card trust series_id; the ids
        // Sonarr acts on must really be that series' (and season's) episodes.
        throw_unless(
            $this->sonarrEpisodeOwnership->allBelongTo($sonarrClient, $seriesId, $episodeIds, $seasonNumber),
            InvalidArgumentException::class,
            sprintf('episode_ids are not all episodes of series %d%s', $seriesId, $seasonNumber === null ? '' : sprintf(' season %d', $seasonNumber)),
        );

        $sonarrClient->setEpisodesMonitored($episodeIds, $monitored);
        new SonarrCache($serviceConnection)->bustAll();

        return [
            'sonarr_series_id' => $seriesId,
            'episode_ids' => $episodeIds,
            'monitored' => $monitored,
        ];
    }

    /**
     * Monitor the series and one of its seasons, then search that season.
     * Sonarr cascades a season's monitored flag to its episodes on update.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function monitorSeason(array $payload): array
    {
        $seriesId = PayloadInt::required($payload, 'series_id');
        $seasonNumber = is_numeric($payload['season_number'] ?? null) ? (int) $payload['season_number'] : -1;

        throw_if($seasonNumber < 0, InvalidArgumentException::class, 'season_number is required');

        $serviceConnection = ServiceConnection::resolvePinnedStrict($payload, ServiceType::Sonarr);

        throw_if($this->pendingReplacementGuard->inFlightForMedia($serviceConnection->id, seriesId: $seriesId), ReplacementInFlight::forTitle());

        $sonarrClient = new SonarrClient($serviceConnection);
        $series = $sonarrClient->fetchSeriesById($seriesId);
        $seasons = is_array($series['seasons'] ?? null) ? $series['seasons'] : [];
        $found = false;

        foreach ($seasons as $index => $season) {
            if (is_array($season) && (int) ($season['seasonNumber'] ?? -1) === $seasonNumber) {
                $seasons[$index]['monitored'] = true;
                $found = true;
            }
        }

        throw_unless($found, InvalidArgumentException::class, sprintf('Series %d has no season %d', $seriesId, $seasonNumber));

        $series['monitored'] = true;
        $series['seasons'] = $seasons;
        $sonarrClient->updateSeries($seriesId, $series);

        $this->searchCommandRunner->run(
            $sonarrClient,
            'sonarr',
            MediaSearchCommand::SeasonSearch,
            MediaSearchCommand::SeasonSearch->arrParameters(['series_id' => $seriesId, 'season_number' => $seasonNumber]),
        );

        new SonarrCache($serviceConnection)->bustAll();

        return [
            'sonarr_series_id' => $seriesId,
            'season_number' => $seasonNumber,
            'monitored' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    private function episodeIds(array $payload): array
    {
        return array_values(array_unique(array_filter(
            array_map(intval(...), (array) ($payload['episode_ids'] ?? [])),
            static fn (int $id): bool => $id > 0,
        )));
    }
}
