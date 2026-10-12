import type { ActionRequestResource } from '@/typefinder/resources/ActionRequestResource';
import { mergeLiveResult } from './failure';

type ActionStatus = ActionRequestResource['status'];

/** An ActionRequestStatusChanged payload, as the Action Queue reads it. */
export interface LiveStatusChange {
    id: number;
    status: ActionStatus;
    /** The member-safe result (null once a retry cleared it). */
    result?: ActionRequestResource['result'];
    updated_at: string;
}

/** Where a status sits in a request's life; the terminal ones tie. */
const LIFECYCLE_STEP: Record<ActionStatus, number> = {
    pending: 0,
    approved: 1,
    executing: 2,
    completed: 3,
    failed: 3,
    rejected: 3,
};

function wholeSeconds(iso: string | null): number {
    const parsed = iso === null ? Number.NaN : Date.parse(iso);

    return Number.isNaN(parsed) ? Number.NaN : Math.floor(parsed / 1000);
}

/**
 * Orders a live status change against a row or an earlier change: above 0
 * when the live change is newer, below 0 when it is older, 0 when they
 * cannot be told apart. `updated_at` is stored to the second, so a pair in
 * the same second is ordered by lifecycle step (pending < approved <
 * executing < completed/failed/rejected). An unreadable live timestamp
 * counts as older, an unreadable other one as newer.
 */
export function compareLiveStatus(
    live: LiveStatusChange,
    other: { status: ActionStatus; updated_at: string | null },
): number {
    const liveAt = wholeSeconds(live.updated_at);
    const otherAt = wholeSeconds(other.updated_at);

    if (Number.isNaN(liveAt)) {
        return -1;
    }

    if (Number.isNaN(otherAt)) {
        return 1;
    }

    if (liveAt !== otherAt) {
        return liveAt - otherAt;
    }

    return LIFECYCLE_STEP[live.status] - LIFECYCLE_STEP[other.status];
}

/**
 * The row a reseed should show: the server row, unless the live change is
 * newer — then the row with the live status and its result folded in the
 * way a live event folds it (mergeLiveResult()).
 */
export function reconcileWithLive(
    row: ActionRequestResource,
    live: LiveStatusChange,
): ActionRequestResource {
    if (compareLiveStatus(live, row) <= 0) {
        return row;
    }

    return {
        ...row,
        status: live.status,
        result: mergeLiveResult(
            row.result,
            live.result,
            row.status === live.status,
        ),
        updated_at: live.updated_at,
    };
}
