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

/** The failure reason of an episode a pending replacement already covers. */
const REPLACEMENT_IN_FLIGHT = 'replacement_in_flight';

/** What a failed episode's row says, by its stored failure reason. */
const FAILURE_LABELS: Partial<Record<string, string>> = {
    [REPLACEMENT_IN_FLIGHT]: 'A replacement is already pending',
    search_expired: 'Search timed out',
};

/** An episode's missing subtitle languages: "ENG, SWE". */
export function missingLanguagesLabel(codes: string[]): string {
    return codes.map((code) => code.toUpperCase()).join(', ');
}

/** Why an episode's search failed, in words; "Search failed" by default. */
export function failureLabel(reason: string | null): string {
    return FAILURE_LABELS[reason ?? ''] ?? 'Search failed';
}

/** The fields the follow-ups read from a batch episode. */
type SubtitleAuditFollowUpEpisode = SubtitleAuditPickTarget & {
    outcome: string;
    failure_reason: string | null;
};

/** What a finished subtitle audit batch still leaves to a person. */
export interface SubtitleAuditFollowUps<
    Episode extends SubtitleAuditFollowUpEpisode,
> {
    /** Episodes with candidates but no confident one, to pick by hand. */
    manualPicks: Episode[];
    /** Failed episodes a replacement still pending elsewhere covers. */
    replacementsInFlight: Episode[];
    /** Episodes whose search found no usable release. */
    nothingFound: number;
    /** Episodes whose search failed for any other reason. */
    failed: number;
}

export function subtitleAuditFollowUps<
    Episode extends SubtitleAuditFollowUpEpisode,
>(episodes: Episode[]): SubtitleAuditFollowUps<Episode> {
    const failed = episodes.filter((episode) => episode.outcome === 'failed');

    return {
        manualPicks: episodes.filter(
            (episode) => episode.outcome === 'manual_pick',
        ),
        replacementsInFlight: failed.filter(
            (episode) => episode.failure_reason === REPLACEMENT_IN_FLIGHT,
        ),
        nothingFound: episodes.filter(
            (episode) => episode.outcome === 'nothing_found',
        ).length,
        failed: failed.filter(
            (episode) => episode.failure_reason !== REPLACEMENT_IN_FLIGHT,
        ).length,
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
