<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;
use InvalidArgumentException;

/**
 * The indexer searches members can start from MediaManager (`search_media`
 * action type). Values are MediaManager's; `arrCommand()` is the upstream
 * Sonarr/Radarr command name.
 */
enum MediaSearchCommand: string
{
    use EnumUtils;

    case SeriesSearch = 'series_search';
    case SeasonSearch = 'season_search';
    case EpisodeSearch = 'episode_search';
    case MissingEpisodeSearch = 'missing_episode_search';
    case CutoffUnmetEpisodeSearch = 'cutoff_unmet_episode_search';
    case MoviesSearch = 'movies_search';
    case MissingMoviesSearch = 'missing_movies_search';
    case CutoffUnmetMoviesSearch = 'cutoff_unmet_movies_search';

    public function label(): string
    {
        return match ($this) {
            self::SeriesSearch => 'Series search',
            self::SeasonSearch => 'Season search',
            self::EpisodeSearch => 'Episode search',
            self::MissingEpisodeSearch => 'All missing episodes',
            self::CutoffUnmetEpisodeSearch => 'All cutoff-unmet episodes',
            self::MoviesSearch => 'Movie search',
            self::MissingMoviesSearch => 'All missing movies',
            self::CutoffUnmetMoviesSearch => 'All cutoff-unmet movies',
        };
    }

    public function arrCommand(): string
    {
        return match ($this) {
            self::SeriesSearch => 'SeriesSearch',
            self::SeasonSearch => 'SeasonSearch',
            self::EpisodeSearch => 'EpisodeSearch',
            self::MissingEpisodeSearch => 'MissingEpisodeSearch',
            self::CutoffUnmetEpisodeSearch => 'CutoffUnmetEpisodeSearch',
            self::MoviesSearch => 'MoviesSearch',
            self::MissingMoviesSearch => 'MissingMoviesSearch',
            self::CutoffUnmetMoviesSearch => 'CutoffUnmetMoviesSearch',
        };
    }

    public function service(): ServiceType
    {
        return match ($this) {
            self::MoviesSearch, self::MissingMoviesSearch, self::CutoffUnmetMoviesSearch => ServiceType::Radarr,
            default => ServiceType::Sonarr,
        };
    }

    public function isLibraryWide(): bool
    {
        return in_array($this, [
            self::MissingEpisodeSearch,
            self::CutoffUnmetEpisodeSearch,
            self::MissingMoviesSearch,
            self::CutoffUnmetMoviesSearch,
        ], true);
    }

    /**
     * The command body fields (besides `name`) for this search. Sonarr's two
     * bulk searches take `monitored`; Radarr's always search monitored movies.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function arrParameters(array $payload): array
    {
        return match ($this) {
            self::SeriesSearch => ['seriesId' => self::positiveId($payload, 'series_id')],
            self::SeasonSearch => ['seriesId' => self::positiveId($payload, 'series_id'), 'seasonNumber' => self::seasonNumber($payload)],
            self::EpisodeSearch => ['episodeIds' => self::positiveIds($payload, 'episode_ids')],
            self::MoviesSearch => ['movieIds' => self::positiveIds($payload, 'movie_ids')],
            self::MissingEpisodeSearch, self::CutoffUnmetEpisodeSearch => ['monitored' => true],
            self::MissingMoviesSearch, self::CutoffUnmetMoviesSearch => [],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function positiveId(array $payload, string $key): int
    {
        $id = (int) ($payload[$key] ?? 0);

        throw_if($id <= 0, InvalidArgumentException::class, sprintf('%s is required', $key));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    private static function positiveIds(array $payload, string $key): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(intval(...), (array) ($payload[$key] ?? [])),
            static fn (int $id): bool => $id > 0,
        )));

        throw_if($ids === [], InvalidArgumentException::class, sprintf('%s is required', $key));

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function seasonNumber(array $payload): int
    {
        $seasonNumber = $payload['season_number'] ?? null;

        throw_unless(is_numeric($seasonNumber) && (int) $seasonNumber >= 0, InvalidArgumentException::class, 'season_number is required');

        return (int) $seasonNumber;
    }
}
