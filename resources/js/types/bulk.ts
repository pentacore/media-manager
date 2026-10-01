export interface BulkFailure {
    id: number | string;
    title: string;
    reason: string;
}

export interface BulkSummary {
    started: number;
    queued: number;
    skipped: number;
    failed: BulkFailure[];
    toast: { type: 'success' | 'info' | 'error'; message: string };
}
