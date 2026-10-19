import type { Episode, SeriesDetail } from './types';

/** The series' poster image URL, preferring Sonarr's remote copy. */
export function posterUrl(series: SeriesDetail): string | null {
    const poster = series.images.find((image) => image.coverType === 'poster');

    return poster?.remoteUrl ?? poster?.url ?? null;
}

/** One season's episodes, in episode order. */
export function episodesForSeason(
    episodes: Episode[],
    seasonNumber: number,
): Episode[] {
    return episodes
        .filter((episode) => episode.season_number === seasonNumber)
        .sort((a, b) => a.episode_number - b.episode_number);
}

export function episodeIdsForSeason(
    episodes: Episode[],
    seasonNumber: number,
): number[] {
    return episodesForSeason(episodes, seasonNumber)
        .map((episode) => episode.id)
        .filter((id): id is number => id !== null);
}

/**
 * Both the season header pill and the season toggle read their monitored
 * state from this — not Sonarr's own season flag: toggling posts episode
 * monitoring, which leaves that flag untouched, so a pill reading it
 * directly would drift out of sync with the toggle it sits beside. Monitored
 * only when every episode is.
 */
export function seasonEpisodesMonitored(
    episodes: Episode[],
    seasonNumber: number,
): boolean {
    const seasonEpisodes = episodesForSeason(episodes, seasonNumber);

    return (
        seasonEpisodes.length > 0 &&
        seasonEpisodes.every((episode) => episode.monitored)
    );
}

export function episodeCode(episode: Episode): string {
    return `S${String(episode.season_number).padStart(2, '0')}E${String(episode.episode_number).padStart(2, '0')}`;
}

/** The series' page in Sonarr's own UI; null without a slug or a URL. */
export function sonarrSeriesUrl(
    connectionUrl: string | null,
    titleSlug: string | null,
): string | null {
    if (!titleSlug || !connectionUrl) {
        return null;
    }

    return `${connectionUrl}/series/${titleSlug}`;
}
