<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AnimeIdMapFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Cross-source id mapping row linking an AniList/MAL anime to its TMDB and
 * TVDB ids (plus the specific TMDB season), sourced from the Fribb
 * anime-lists dataset or a user-confirmed fuzzy match.
 *
 * @property int $id
 * @property int|null $anilist_id
 * @property int|null $mal_id
 * @property int|null $tmdb_tv_id
 * @property int|null $tmdb_movie_id
 * @property int|null $tvdb_id
 * @property int|null $tmdb_season
 * @property string|null $type
 * @property bool $user_confirmed
 */
#[Fillable([
    'anilist_id',
    'mal_id',
    'tmdb_tv_id',
    'tmdb_movie_id',
    'tvdb_id',
    'tmdb_season',
    'type',
    'user_confirmed',
])]
class AnimeIdMap extends Model
{
    /** @use HasFactory<AnimeIdMapFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'anilist_id' => 'integer',
            'mal_id' => 'integer',
            'tmdb_tv_id' => 'integer',
            'tmdb_movie_id' => 'integer',
            'tvdb_id' => 'integer',
            'tmdb_season' => 'integer',
            'user_confirmed' => 'boolean',
        ];
    }
}
