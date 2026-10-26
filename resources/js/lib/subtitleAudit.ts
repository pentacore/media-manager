import SeriesController from '@/actions/App/Http/Controllers/Media/SeriesController';

/** The episode identity a subtitle audit pick link needs. */
export interface SubtitleAuditPickTarget {
    season_number: number;
    episode_number: number;
}

/**
 * The series page with the replace dialog open on one episode, where a
 * subtitle audit episode without a confident release is picked by hand. The
 * chat's batch card and the Action Queue's batch view link the same way.
 */
export function subtitleAuditPickUrl(
    seriesId: number,
    episode: SubtitleAuditPickTarget,
): string {
    return SeriesController.show.url(seriesId, {
        query: {
            replace_season: episode.season_number,
            replace_episode: episode.episode_number,
        },
    });
}

/** What a finished subtitle audit batch still leaves to a person. */
export interface SubtitleAuditFollowUps<
    Episode extends SubtitleAuditPickTarget & { outcome: string },
> {
    /** Episodes with candidates but no confident one, to pick by hand. */
    manualPicks: Episode[];
    /** Episodes whose search found no usable release. */
    nothingFound: number;
    /** Episodes whose search failed. */
    failed: number;
}

export function subtitleAuditFollowUps<
    Episode extends SubtitleAuditPickTarget & { outcome: string },
>(episodes: Episode[]): SubtitleAuditFollowUps<Episode> {
    return {
        manualPicks: episodes.filter(
            (episode) => episode.outcome === 'manual_pick',
        ),
        nothingFound: episodes.filter(
            (episode) => episode.outcome === 'nothing_found',
        ).length,
        failed: episodes.filter((episode) => episode.outcome === 'failed')
            .length,
    };
}

/** True when the batch left anything for a person to look at. */
export function hasSubtitleAuditFollowUps(
    episodes: Array<{ outcome: string }>,
): boolean {
    return episodes.some((episode) =>
        ['manual_pick', 'nothing_found', 'failed'].includes(episode.outcome),
    );
}
