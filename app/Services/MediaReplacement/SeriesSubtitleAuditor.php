<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Cache\Services\SonarrCache;
use App\Enums\MediaReplacementScope;
use App\Models\ServiceConnection;
use App\Services\Arr\ArrConnections;

/**
 * Reports every episode of a Sonarr series as ok, missing or skipped, reading
 * only Sonarr's episodefile mediaInfo through the rules the import check uses.
 * Read-only: it never searches, dispatches or notifies.
 */
final readonly class SeriesSubtitleAuditor
{
    public function __construct(
        private ArrConnections $arrConnections,
        private SonarrMediaScopeResolver $sonarrMediaScopeResolver,
        private MediaFileInspector $mediaFileInspector,
        private SubtitleCheckRules $subtitleCheckRules,
        private PendingReplacementGuard $pendingReplacementGuard,
    ) {}

    /**
     * @throws SeriesSubtitleAuditUnavailable when the series' library type is not configured
     */
    public function audit(ServiceConnection $serviceConnection, int $seriesId, ?int $seasonNumber = null): SeriesSubtitleAudit
    {
        new SonarrCache($serviceConnection)->bustAll();

        $sonarrClient = $this->arrConnections->sonarr($serviceConnection);
        $series = $sonarrClient->getSeriesById($seriesId);
        $scope = $this->sonarrMediaScopeResolver->resolve($serviceConnection, $series);

        throw_unless($scope instanceof MediaReplacementScope, SeriesSubtitleAuditUnavailable::class, 'unconfigured_root_scope');

        $episodes = $sonarrClient->getEpisodesBySeries($seriesId);
        $subtitlesByFileId = [];

        foreach ($sonarrClient->getEpisodeFiles($seriesId) as $episodeFile) {
            $subtitlesByFileId[(int) ($episodeFile['id'] ?? 0)] = $this->mediaFileInspector
                ->subtitleLanguages($episodeFile['mediaInfo']['subtitles'] ?? null);
        }

        $fileUsage = array_count_values(array_filter(
            array_map(static fn (array $episode): int => (int) ($episode['episodeFileId'] ?? 0), $episodes),
            static fn (int $fileId): bool => $fileId > 0,
        ));

        $results = [];

        foreach ($episodes as $episode) {
            $season = (int) ($episode['seasonNumber'] ?? 0);

            if ($seasonNumber !== null && $season !== $seasonNumber) {
                continue;
            }

            $results[] = $this->judge($serviceConnection, $seriesId, $episode, $scope, $subtitlesByFileId, $fileUsage, $seasonNumber);
        }

        return new SeriesSubtitleAudit(
            serviceConnectionId: $serviceConnection->id,
            seriesId: $seriesId,
            seriesTitle: (string) ($series['title'] ?? ''),
            scope: $scope,
            seasonNumber: $seasonNumber,
            episodes: $results,
        );
    }

    /**
     * @param  array<string, mixed>  $episode
     * @param  array<int, list<string>>  $subtitlesByFileId
     * @param  array<int, int>  $fileUsage
     */
    private function judge(
        ServiceConnection $serviceConnection,
        int $seriesId,
        array $episode,
        MediaReplacementScope $scope,
        array $subtitlesByFileId,
        array $fileUsage,
        ?int $askedSeason,
    ): SeriesSubtitleAuditEpisode {
        $episodeId = (int) ($episode['id'] ?? 0);
        $season = (int) ($episode['seasonNumber'] ?? 0);
        $number = (int) ($episode['episodeNumber'] ?? 0);
        $fileId = (int) ($episode['episodeFileId'] ?? 0);

        $skip = static fn (string $reason): SeriesSubtitleAuditEpisode => new SeriesSubtitleAuditEpisode(
            $episodeId, $season, $number, SeriesSubtitleAuditEpisode::STATUS_SKIPPED, [], $reason,
        );

        if ($askedSeason === null && $season === 0) {
            return $skip('special');
        }

        if ($fileId <= 0) {
            return $skip('no_file');
        }

        if (($episode['monitored'] ?? false) !== true) {
            return $skip('unmonitored');
        }

        if (($fileUsage[$fileId] ?? 0) > 1) {
            return $skip('shared_file');
        }

        $missing = $this->subtitleCheckRules->missingLanguages($subtitlesByFileId[$fileId] ?? [], $scope);

        if ($missing === []) {
            return new SeriesSubtitleAuditEpisode($episodeId, $season, $number, SeriesSubtitleAuditEpisode::STATUS_OK);
        }

        if ($this->pendingReplacementGuard->inFlightFor([
            'service_connection_id' => $serviceConnection->id,
            'series_id' => $seriesId,
            'season_number' => $season,
            'episode_numbers' => [$number],
        ])) {
            return $skip('replacement_in_flight');
        }

        $autoCheckKey = $this->subtitleCheckRules->autoCheckKey($serviceConnection, [
            'series_id' => $seriesId,
            'episode_ids' => [$episodeId],
        ]);

        if ($this->subtitleCheckRules->capReached($autoCheckKey)) {
            return $skip('cap_reached');
        }

        return new SeriesSubtitleAuditEpisode($episodeId, $season, $number, SeriesSubtitleAuditEpisode::STATUS_MISSING, $missing);
    }
}
