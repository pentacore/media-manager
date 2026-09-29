export type CalendarState = 'downloaded' | 'missing' | 'upcoming' | 'unmonitored';

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
    monitored: boolean;
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
