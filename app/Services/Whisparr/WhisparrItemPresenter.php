<?php

declare(strict_types=1);

namespace App\Services\Whisparr;

use App\Enums\WhisparrVersion;
use Illuminate\Support\Str;

/**
 * Turns Whisparr items into one browser row shape for both API versions:
 * v2 (Eros) is Sonarr-shaped — a `series` with `statistics` and seasons
 * numbered by year — and v3 is Radarr-shaped — a `movie` with `hasFile` and
 * `sizeOnDisk`. Every key is present for both versions (null, false or 0 when
 * upstream omits or mistypes it), and only whitelisted fields are copied, so
 * nothing credential-bearing can reach the browser.
 *
 * @phpstan-type WhisparrRow array{id: int, kind: 'site'|'movie', title: string, year: int|null, monitored: bool, has_file: bool, size_bytes: int, poster_url: string|null, quality_profile_id: int|null}
 * @phpstan-type WhisparrDetail array{id: int, kind: 'site'|'movie', title: string, year: int|null, monitored: bool, has_file: bool, size_bytes: int, poster_url: string|null, quality_profile_id: int|null, path: string|null, overview: string|null}
 * @phpstan-type WhisparrScene array{id: int, title: string|null, air_date: string|null, has_file: bool, monitored: bool}
 * @phpstan-type WhisparrSceneGroup array{year: int, scenes: list<WhisparrScene>}
 */
final readonly class WhisparrItemPresenter
{
    /**
     * @param  array<array-key, mixed>  $items
     * @return list<WhisparrRow>
     */
    public function rows(WhisparrVersion $whisparrVersion, array $items): array
    {
        $rows = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = $this->row($whisparrVersion, $item);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return WhisparrRow|null null when the item has no usable id
     */
    public function row(WhisparrVersion $whisparrVersion, array $item): ?array
    {
        $id = $this->parseId($item['id'] ?? null);

        if ($id === null) {
            return null;
        }

        $isV2 = $whisparrVersion === WhisparrVersion::V2;
        $statistics = is_array($item['statistics'] ?? null) ? $item['statistics'] : [];
        $title = $item['title'] ?? null;
        $size = $isV2 ? ($statistics['sizeOnDisk'] ?? 0) : ($item['sizeOnDisk'] ?? $statistics['sizeOnDisk'] ?? 0);

        return [
            'id' => $id,
            'kind' => $isV2 ? 'site' : 'movie',
            'title' => is_string($title) && $title !== '' ? $title : sprintf('#%d', $id),
            'year' => $this->positiveInt($item['year'] ?? null),
            'monitored' => ($item['monitored'] ?? false) === true,
            'has_file' => $isV2
                ? is_int($statistics['episodeFileCount'] ?? null) && $statistics['episodeFileCount'] > 0
                : ($item['hasFile'] ?? false) === true,
            'size_bytes' => is_int($size) && $size > 0 ? $size : 0,
            'poster_url' => $this->posterUrl($item['images'] ?? null),
            'quality_profile_id' => $this->positiveInt($item['qualityProfileId'] ?? null),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return WhisparrDetail|null
     */
    public function detail(WhisparrVersion $whisparrVersion, array $item): ?array
    {
        $row = $this->row($whisparrVersion, $item);

        if ($row === null) {
            return null;
        }

        $path = $item['path'] ?? null;
        $overview = $item['overview'] ?? null;

        return [
            ...$row,
            'path' => is_string($path) && $path !== '' ? $path : null,
            'overview' => is_string($overview) && $overview !== '' ? $overview : null,
        ];
    }

    /**
     * Whisparr v2 scenes (episodes) grouped by year. v2 numbers its seasons
     * by release year; a scene without one falls back to its air date's year,
     * then to 0 ("unknown"). Newest year first, newest scene first.
     *
     * @param  array<array-key, mixed>  $episodes
     * @return list<WhisparrSceneGroup>
     */
    public function sceneGroups(array $episodes): array
    {
        $groups = [];

        foreach ($episodes as $episode) {
            if (! is_array($episode)) {
                continue;
            }

            $id = $this->parseId($episode['id'] ?? null);

            if ($id === null) {
                continue;
            }

            $airDate = is_string($episode['airDate'] ?? null) && $episode['airDate'] !== '' ? $episode['airDate'] : null;
            $year = $this->positiveInt($episode['seasonNumber'] ?? null)
                ?? ($airDate !== null ? (int) Str::substr($airDate, 0, 4) : 0);
            $title = $episode['title'] ?? null;

            $groups[$year][] = [
                'id' => $id,
                'title' => is_string($title) && $title !== '' ? $title : null,
                'air_date' => $airDate,
                'has_file' => ($episode['hasFile'] ?? false) === true,
                'monitored' => ($episode['monitored'] ?? false) === true,
            ];
        }

        krsort($groups);

        $result = [];

        foreach ($groups as $year => $scenes) {
            usort($scenes, static fn (array $a, array $b): int => strcmp((string) $b['air_date'], (string) $a['air_date']));
            $result[] = ['year' => $year, 'scenes' => $scenes];
        }

        return $result;
    }

    /**
     * Only the poster's `remoteUrl`: the arr `url` is a path on the Whisparr
     * host, which the browser cannot load from MediaManager, and copying it
     * (or any other upstream field) could leak the `apikey` query string
     * Whisparr sometimes appends to local MediaCover links.
     */
    private function posterUrl(mixed $images): ?string
    {
        if (! is_array($images)) {
            return null;
        }

        foreach ($images as $image) {
            if (is_array($image) && ($image['coverType'] ?? null) === 'poster') {
                $remoteUrl = $image['remoteUrl'] ?? null;

                return is_string($remoteUrl) && $remoteUrl !== '' ? $remoteUrl : null;
            }
        }

        return null;
    }

    /**
     * An id must resolve to a positive int to be usable. Upstream sends a
     * real int; a numeric string ("12") is still coerced, since some
     * Whisparr v2 responses stringify ids — anything else (non-numeric,
     * missing, zero or negative) is unusable.
     */
    private function parseId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return $this->positiveInt((int) $value);
        }

        return null;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
