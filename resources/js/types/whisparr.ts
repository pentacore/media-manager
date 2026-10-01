import type { QualityProfileOption } from './library';

export type WhisparrKind = 'site' | 'movie';

export interface WhisparrItem {
    id: number;
    kind: WhisparrKind;
    title: string;
    year: number | null;
    monitored: boolean;
    has_file: boolean;
    /** Monitored and short of files: v3 has no file, v2 has fewer scene files than scenes. */
    missing: boolean;
    size_bytes: number;
    poster_url: string | null;
    quality_profile_id: number | null;
}

export interface WhisparrItemDetail extends WhisparrItem {
    path: string | null;
    overview: string | null;
}

export interface WhisparrScene {
    id: number;
    title: string | null;
    air_date: string | null;
    has_file: boolean;
    monitored: boolean;
}

export interface WhisparrSceneGroup {
    year: number;
    scenes: WhisparrScene[];
}

export interface WhisparrConnection {
    id: number;
    name: string;
    version: 'v2' | 'v3';
    url: string;
}

export interface WhisparrLibrary {
    items: WhisparrItem[];
    error: string | null;
}

export interface WhisparrQualityProfiles {
    items: QualityProfileOption[];
    error: string | null;
}

export interface WhisparrScenes {
    groups: WhisparrSceneGroup[];
    error: string | null;
}
