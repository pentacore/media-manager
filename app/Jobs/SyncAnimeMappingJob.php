<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AnimeIdMap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes the `anime_id_maps` table from the Fribb anime-lists dataset,
 * which maps AniList/MAL ids onto TMDB (tv/movie) + TVDB ids and the specific
 * TMDB season. User-confirmed rows are preserved across reloads.
 *
 * @see https://github.com/Fribb/anime-lists
 */
class SyncAnimeMappingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 120;

    public int $timeout = 600;

    private const int CHUNK = 1000;

    public function handle(): void
    {
        $url = (string) config('mediamanager.anime.mapping_url');

        $dataset = Http::acceptJson()
            ->timeout(120)
            ->connectTimeout(10)
            ->withUserAgent('MediaManager/'.config('app.version').' SyncAnimeMappingJob')
            ->retry(3, fn (int $attempt): int => $attempt * 1000, throw: false)
            ->get($url)
            ->throw()
            ->json();

        if (! is_array($dataset)) {
            Log::warning('SyncAnimeMappingJob: unexpected dataset payload', ['url' => $url]);

            return;
        }

        $now = now();
        $rows = [];
        $count = 0;

        // Wipe only dataset-sourced rows; user-confirmed matches survive.
        DB::transaction(function () use ($dataset, $now, &$rows, &$count): void {
            AnimeIdMap::query()->where('user_confirmed', false)->delete();

            foreach ($dataset as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $row = $this->mapRow($entry, $now);

                if ($row === null) {
                    continue;
                }

                $rows[] = $row;
                $count++;

                if (count($rows) >= self::CHUNK) {
                    AnimeIdMap::query()->insert($rows);
                    $rows = [];
                }
            }

            if ($rows !== []) {
                AnimeIdMap::query()->insert($rows);
            }
        });

        Log::info('SyncAnimeMappingJob: reloaded anime id map', ['rows' => $count]);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private function mapRow(array $entry, mixed $now): ?array
    {
        $anilistId = isset($entry['anilist_id']) ? (int) $entry['anilist_id'] : null;
        $malId = isset($entry['mal_id']) ? (int) $entry['mal_id'] : null;

        // No AniList/MAL key means we can never match it to a seasonal entry.
        if ($anilistId === null && $malId === null) {
            return null;
        }

        [$tmdbTvId, $tmdbMovieId] = $this->extractTmdbIds($entry['themoviedb_id'] ?? null, (string) ($entry['type'] ?? ''));

        return [
            'anilist_id' => $anilistId,
            'mal_id' => $malId,
            'tmdb_tv_id' => $tmdbTvId,
            'tmdb_movie_id' => $tmdbMovieId,
            'tvdb_id' => isset($entry['tvdb_id']) && is_numeric($entry['tvdb_id']) ? (int) $entry['tvdb_id'] : null,
            'tmdb_season' => isset($entry['season']['tmdb']) ? (int) $entry['season']['tmdb'] : null,
            'type' => $entry['type'] ?? null,
            'user_confirmed' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Fribb stores `themoviedb_id` as `{tv: id}` / `{movie: id}`, or sometimes
     * a bare int whose media type we infer from the entry `type`.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function extractTmdbIds(mixed $tmdb, string $type): array
    {
        if (is_array($tmdb)) {
            $tv = isset($tmdb['tv']) ? (int) $tmdb['tv'] : null;
            $movie = isset($tmdb['movie']) ? (int) $tmdb['movie'] : null;

            return [$tv, $movie];
        }

        if (is_numeric($tmdb)) {
            $id = (int) $tmdb;

            return strtoupper($type) === 'MOVIE' ? [null, $id] : [$id, null];
        }

        return [null, null];
    }
}
