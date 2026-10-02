<?php

declare(strict_types=1);

namespace App\Services\Sonarr;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Whether episode ids really are episodes of the series (and season) a
 * request names. The replacement guard, the Action Queue card and the season
 * wording key on series_id/season_number while Sonarr acts on bare episode
 * ids, so an unchecked pairing lets one series' guard or description cover
 * another series' episodes. Episode ids never move between series, so the
 * cached episode list answers; when it lacks an id, one uncached re-read
 * covers an episode Sonarr added after the list was cached.
 */
final readonly class SonarrEpisodeOwnership
{
    /**
     * @param  list<int>  $episodeIds
     *
     * @throws RequestException|ConnectionException
     */
    public function allBelongTo(SonarrClient $sonarrClient, int $seriesId, array $episodeIds, ?int $seasonNumber = null): bool
    {
        if ($episodeIds === []) {
            return false;
        }

        return $this->covers($sonarrClient->getEpisodesBySeries($seriesId), $episodeIds, $seasonNumber)
            || $this->covers($sonarrClient->fetchEpisodesBySeries($seriesId), $episodeIds, $seasonNumber);
    }

    /**
     * @param  array<int, mixed>  $episodes
     * @param  list<int>  $episodeIds
     */
    private function covers(array $episodes, array $episodeIds, ?int $seasonNumber): bool
    {
        $seasonOf = [];

        foreach ($episodes as $episode) {
            if (! is_array($episode) || ! is_int($episode['id'] ?? null)) {
                continue;
            }

            $seasonOf[$episode['id']] = is_int($episode['seasonNumber'] ?? null) ? $episode['seasonNumber'] : null;
        }

        foreach ($episodeIds as $episodeId) {
            if (! array_key_exists($episodeId, $seasonOf)) {
                return false;
            }

            if ($seasonNumber !== null && $seasonOf[$episodeId] !== $seasonNumber) {
                return false;
            }
        }

        return true;
    }
}
