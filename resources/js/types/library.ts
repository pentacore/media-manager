export type MediaSearchCommand =
    | 'series_search'
    | 'season_search'
    | 'episode_search'
    | 'missing_episode_search'
    | 'cutoff_unmet_episode_search'
    | 'movies_search'
    | 'missing_movies_search'
    | 'cutoff_unmet_movies_search';

export interface ReleaseRow {
    key: string;
    indexer_id: number;
    title: string;
    quality: string | null;
    size: number;
    age_hours: number | null;
    peers: number | null;
    protocol: string | null;
    indexer: string | null;
    rejected: boolean;
    rejections: string[];
}

export interface QualityProfileOption {
    id: number;
    name: string;
}
