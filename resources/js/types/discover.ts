export type TitleStatus =
    | 'available'
    | 'partially_available'
    | 'requested'
    | 'pending'
    | 'none';

export type DiscoverMediaType = 'movie' | 'tv';

export interface DiscoverTitle {
    tmdb_id: number;
    media_type: DiscoverMediaType;
    title: string;
    year: number | null;
    poster_path: string | null;
    backdrop_path: string | null;
    overview: string | null;
    release_date: string | null;
    status: TitleStatus;
}

export interface DiscoverSeason {
    season_number: number;
    name: string;
    episode_count: number;
    status: TitleStatus;
    requestable: boolean;
}

export interface DiscoverTitleDetail extends DiscoverTitle {
    rating: number | null;
    runtime: number | null;
    season_count: number | null;
    seasons: DiscoverSeason[];
}

export interface DiscoverRowPayload {
    results: DiscoverTitle[];
    error: string | null;
}

export interface RequestingContext {
    canChooseUser: boolean;
    userId: number | null;
    users: { id: number; label: string }[];
    error: string | null;
}
