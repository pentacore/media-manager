<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Cache\Services\AnimeCache;
use App\Models\AnimeIdMap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Refreshes the `anime_id_maps` table from the Fribb anime-lists dataset,
 * which maps AniList/MAL ids onto TMDB (tv/movie) + TVDB ids and the specific
 * TMDB season. User-confirmed rows are preserved across reloads.
 *
 * The current table is only deleted once a credible replacement dataset has
 * been fully parsed, so a truncated/empty/error payload can never wipe the
 * mappings. ShouldBeUnique + a timeout below the queue `retry_after` prevent a
 * second worker from reserving this destructive job while it is still running.
 *
 * @see https://github.com/Fribb/anime-lists
 */
class SyncAnimeMappingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 120;

    /**
     * Kept below the smallest queue `retry_after` (330s) so a second worker
     * cannot reserve and re-run this destructive refresh mid-flight.
     */
    public int $timeout = 300;

    /**
     * Minimum credible mappable-row count. A parsed dataset below this is
     * treated as corrupt and the existing table is left untouched.
     */
    private const int MIN_ROWS = 1000;

    private const int CHUNK = 1000;

    private const string LAST_SUCCESS_KEY = 'anime:mapping:last-synced-at';

    public function uniqueId(): string
    {
        return 'sync-anime-mapping';
    }

    /**
     * Reload the mapping table. Returns the number of dataset rows written.
     * Throws (keeping the current table intact) when the payload is unusable,
     * so both the queue (via failed()) and the console command surface it
     * instead of silently reporting success on an empty import.
     *
     * @throws InvalidArgumentException when the dataset is missing/malformed/undersized
     */
    public function handle(): int
    {
        $url = (string) config('mediamanager.anime.mapping_url');

        $dataset = Http::acceptJson()
            ->timeout(120)
            ->connectTimeout(10)
            ->withUserAgent('MediaManager/'.config('app.version').' SyncAnimeMappingJob')
            ->retry(
                times: 3,
                sleepMilliseconds: fn (int $attempt): int => $attempt * 500,
                when: fn (Throwable $throwable): bool => $throwable instanceof RequestException
                    ? $throwable->response->serverError()
                    : true,
                throw: false,
            )
            ->get($url)
            ->throw()
            ->json();

        // Require a non-empty, list-shaped payload — is_array() alone also
        // accepts {} and error objects, which would otherwise reach the delete.
        if (! is_array($dataset) || $dataset === [] || ! array_is_list($dataset)) {
            throw new InvalidArgumentException('SyncAnimeMappingJob: dataset payload is not a non-empty list.');
        }

        $now = now();

        $rows = [];
        foreach ($dataset as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $row = $this->mapRow($entry, $now);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        // Guard against a schema break or partial download replacing ~32k good
        // rows with a handful of garbage ones. Only rows with a usable TMDB
        // destination id are counted (see mapRow).
        if (count($rows) < self::MIN_ROWS) {
            throw new InvalidArgumentException(sprintf(
                'SyncAnimeMappingJob: parsed only %d usable rows (minimum %d); keeping current mappings.',
                count($rows),
                self::MIN_ROWS,
            ));
        }

        // Wipe only dataset-sourced rows; user-confirmed matches survive.
        DB::transaction(function () use ($rows): void {
            AnimeIdMap::query()->where('user_confirmed', false)->delete();

            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                AnimeIdMap::query()->insert($chunk);
            }
        });

        new AnimeCache()->bustAll();
        Cache::forever(self::LAST_SUCCESS_KEY, $now->toIso8601String());

        Log::info('SyncAnimeMappingJob: reloaded anime id map', ['rows' => count($rows)]);

        return count($rows);
    }

    public function failed(?Throwable $throwable): void
    {
        Log::error('SyncAnimeMappingJob failed; anime mappings may be stale', [
            'message' => $throwable?->getMessage(),
            'last_synced_at' => Cache::get(self::LAST_SUCCESS_KEY),
        ]);
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

        // A row with no TMDB destination is unrequestable via Seerr, so it is
        // not a usable mapping — drop it (and keep it out of the row count that
        // gates the destructive reload).
        if ($tmdbTvId === null && $tmdbMovieId === null) {
            return null;
        }

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
     * Fribb stores `themoviedb_id` as `{tv: id}` / `{movie: [id, ...]}` (movie
     * ids are arrays and may list several TMDB entries for one anime; tv ids
     * are scalar), or occasionally a bare int whose media type is inferred from
     * the entry `type`.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function extractTmdbIds(mixed $tmdb, string $type): array
    {
        if (is_array($tmdb) && ! array_is_list($tmdb)) {
            return [
                $this->pickId($tmdb['tv'] ?? null),
                $this->pickId($tmdb['movie'] ?? null),
            ];
        }

        if (is_numeric($tmdb)) {
            $id = (int) $tmdb;

            return strtoupper($type) === 'MOVIE' ? [null, $id] : [$id, null];
        }

        return [null, null];
    }

    /**
     * Normalize a TMDB id that may arrive as a scalar or as an array of ids.
     * When Fribb lists multiple ids for one anime the lowest is chosen: the
     * selection must be deterministic and independent of Fribb's array order,
     * and the lowest id is the earliest-created (canonical) TMDB record.
     */
    private function pickId(mixed $value): ?int
    {
        if (is_array($value)) {
            $ids = array_filter(array_map(
                fn (mixed $id): ?int => is_numeric($id) ? (int) $id : null,
                $value,
            ), fn (?int $id): bool => $id !== null);

            return $ids === [] ? null : min($ids);
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
