export interface QualityProfile {
    id: number;
    name: string;
}

export interface SeriesImage {
    coverType: string;
    remoteUrl?: string;
    url?: string;
}

export interface Episode {
    id: number | null;
    season_number: number;
    episode_number: number;
    title: string | null;
    air_date: string | null;
    has_file: boolean;
    monitored: boolean;
    overview: string | null;
}

export interface Season {
    season_number: number;
    monitored: boolean;
    episode_count: number;
    episode_file_count: number;
    size_on_disk: number;
}

export interface SeriesDetail {
    id: number;
    title: string;
    title_slug: string | null;
    year: number | null;
    status: string | null;
    monitored: boolean;
    quality_profile_id: number | null;
    season_count: number;
    size_on_disk: number;
    episode_file_count: number;
    episode_count: number;
    images: SeriesImage[];
    overview: string | null;
    network: string | null;
    runtime: number | null;
    root_folder_path?: string | null;
    seasons: Season[];
}
