<?php

declare(strict_types=1);

namespace App\Services\Anime;

use App\Enums\AnimeSeason;
use Illuminate\Support\Collection;

interface SeasonalAnimeSource
{
    /**
     * Fetch every anime airing in the given broadcast season, across all
     * formats, walking pagination until exhausted.
     *
     * @return Collection<int, SeasonalAnimeEntry>
     */
    public function fetchSeason(int $year, AnimeSeason $animeSeason): Collection;

    /**
     * Stable slug identifying this source (e.g. 'anilist', 'jikan'). Used in
     * cache keys and the UI source toggle.
     */
    public function slug(): string;
}
