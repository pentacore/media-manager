<?php

declare(strict_types=1);

namespace App\Services\Anime;

use App\Enums\AnimeFormat;
use App\Models\AnimeIdMap;
use Illuminate\Support\Collection;

/**
 * Resolves seasonal anime entries onto TMDB/TVDB ids using the local
 * `anime_id_maps` table (Fribb dataset + user-confirmed matches). Lookups are
 * batched so a full season grid costs a single query per id column.
 */
class AnimeIdMapper
{
    /**
     * Resolve a batch of entries, keyed by their stable entry key so the
     * caller can attach each mapping back to its card.
     *
     * @param  Collection<int, SeasonalAnimeEntry>  $entries
     * @return array<string, AnimeMapping>
     */
    public function resolveMany(Collection $entries): array
    {
        $anilistIds = $entries->pluck('anilistId')->filter()->unique()->values();
        $malIds = $entries->pluck('malId')->filter()->unique()->values();

        // Order so user-confirmed rows come *last*: keyBy() keeps the final
        // row seen for a duplicate key, so ascending order lets a confirmed
        // row (user_confirmed = true) win over a dataset row for the same id.
        $byAnilist = AnimeIdMap::query()
            ->whereIn('anilist_id', $anilistIds)
            ->orderBy('user_confirmed')
            ->get()
            ->keyBy('anilist_id');

        $byMal = AnimeIdMap::query()
            ->whereIn('mal_id', $malIds)
            ->orderBy('user_confirmed')
            ->get()
            ->keyBy('mal_id');

        $resolved = [];

        foreach ($entries as $entry) {
            $row = ($entry->anilistId !== null ? $byAnilist->get($entry->anilistId) : null)
                ?? ($entry->malId !== null ? $byMal->get($entry->malId) : null);

            $resolved[$this->entryKey($entry)] = $this->toMapping($row, $entry->format);
        }

        return $resolved;
    }

    /**
     * A stable key for an entry across sources: prefer AniList id, fall back
     * to a namespaced MAL id.
     */
    public function entryKey(SeasonalAnimeEntry $seasonalAnimeEntry): string
    {
        if ($seasonalAnimeEntry->anilistId !== null) {
            return 'anilist:'.$seasonalAnimeEntry->anilistId;
        }

        return 'mal:'.$seasonalAnimeEntry->malId;
    }

    /**
     * Persist a user-confirmed fuzzy match so the entry maps automatically
     * next time. Overwrites any existing confirmed row for the same key. The
     * TMDB id is stored in the column matching the *chosen candidate's* media
     * type, which may differ from the anime's format.
     *
     * @param  'tv'|'movie'  $mediaType
     */
    public function persistConfirmedMatch(
        ?int $anilistId,
        ?int $malId,
        int $tmdbId,
        string $mediaType,
    ): AnimeIdMap {
        return AnimeIdMap::query()->updateOrCreate(
            $anilistId !== null
                ? ['anilist_id' => $anilistId, 'user_confirmed' => true]
                : ['mal_id' => $malId, 'user_confirmed' => true],
            [
                'anilist_id' => $anilistId,
                'mal_id' => $malId,
                'tmdb_tv_id' => $mediaType === 'tv' ? $tmdbId : null,
                'tmdb_movie_id' => $mediaType === 'movie' ? $tmdbId : null,
                'type' => strtoupper($mediaType),
                'user_confirmed' => true,
            ],
        );
    }

    private function toMapping(?AnimeIdMap $animeIdMap, AnimeFormat $animeFormat): AnimeMapping
    {
        if (! $animeIdMap instanceof AnimeIdMap) {
            return AnimeMapping::unmapped($animeFormat);
        }

        // The id and its media type must come from the same populated column:
        // TMDB tv/movie ids overlap numerically, and Fribb maps some MOVIE
        // entries onto TMDB tv records (and vice versa). Prefer the format's
        // own namespace, but when only the other column is populated adopt
        // *that* column's media type so we never request an unrelated title.
        [$tmdbId, $mediaType] = $animeFormat->seerrMediaType() === 'movie'
            ? ($animeIdMap->tmdb_movie_id !== null
                ? [$animeIdMap->tmdb_movie_id, 'movie']
                : [$animeIdMap->tmdb_tv_id, 'tv'])
            : ($animeIdMap->tmdb_tv_id !== null
                ? [$animeIdMap->tmdb_tv_id, 'tv']
                : [$animeIdMap->tmdb_movie_id, 'movie']);

        if ($tmdbId === null) {
            return AnimeMapping::unmapped($animeFormat);
        }

        return new AnimeMapping(
            tmdbId: $tmdbId,
            mediaType: $mediaType,
            tvdbId: $animeIdMap->tvdb_id,
            tmdbSeason: $animeIdMap->tmdb_season,
        );
    }
}
