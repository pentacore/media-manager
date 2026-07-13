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
     * next time. Overwrites any existing confirmed row for the same key.
     */
    public function persistConfirmedMatch(
        ?int $anilistId,
        ?int $malId,
        int $tmdbId,
        AnimeFormat $animeFormat,
    ): AnimeIdMap {
        $mediaType = $animeFormat->seerrMediaType();

        return AnimeIdMap::query()->updateOrCreate(
            $anilistId !== null
                ? ['anilist_id' => $anilistId, 'user_confirmed' => true]
                : ['mal_id' => $malId, 'user_confirmed' => true],
            [
                'anilist_id' => $anilistId,
                'mal_id' => $malId,
                'tmdb_tv_id' => $mediaType === 'tv' ? $tmdbId : null,
                'tmdb_movie_id' => $mediaType === 'movie' ? $tmdbId : null,
                'type' => strtoupper($animeFormat->value),
                'user_confirmed' => true,
            ],
        );
    }

    private function toMapping(?AnimeIdMap $animeIdMap, AnimeFormat $animeFormat): AnimeMapping
    {
        if (! $animeIdMap instanceof AnimeIdMap) {
            return AnimeMapping::unmapped($animeFormat);
        }

        $mediaType = $animeFormat->seerrMediaType();
        $tmdbId = $mediaType === 'movie'
            ? ($animeIdMap->tmdb_movie_id ?? $animeIdMap->tmdb_tv_id)
            : ($animeIdMap->tmdb_tv_id ?? $animeIdMap->tmdb_movie_id);

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
