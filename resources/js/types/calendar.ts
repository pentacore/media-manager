export type CalendarState =
    'downloaded' | 'missing' | 'upcoming' | 'unmonitored';

export interface CalendarItem {
    key: string;
    service: 'sonarr' | 'radarr';
    service_connection_id: number;
    instance: string | null;
    title: string;
    episode_title: string | null;
    code: string;
    air_date_utc: string;
    state: CalendarState;
    /** Display state: false for an episode of an unmonitored series. */
    monitored: boolean;
    /** The episode's own flag (what its Monitor toggle flips); null for movies. */
    episode_monitored: boolean | null;
    poster_url: string | null;
    library_url: string | null;
    series_id: number | null;
    episode_id: number | null;
    movie_id: number | null;
}

export interface CalendarPayload {
    items: CalendarItem[];
    failures: { service: string; instance: string }[];
}
