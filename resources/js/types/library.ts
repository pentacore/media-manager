export type { MediaSearchCommand } from '@/typefinder';

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

/** A deferred upstream list: an outage is `error`, never an empty `items`. */
export interface UpstreamList<T> {
    items: T[];
    error: string | null;
}
