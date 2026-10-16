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
    /**
     * The row's status version (action_requests.status_version, a database
     * sequence taken on every status change). Missing from a broadcast built
     * before the column existed.
     */
    status_version?: number | null;
}

/** What a live change is ordered against: a listed row or an earlier change. */
interface StatusSnapshot {
    status: ActionStatus;
    updated_at: string | null;
    status_version?: number | null;
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
 * when the live change is newer, below 0 when it is older, 0 when it is the
 * same change or they cannot be told apart. When both carry a status
 * version, the version decides, so a same-second change by someone else and
 * a host whose clock runs behind are ordered right. Otherwise (a broadcast
 * without a version) the old rule applies: `updated_at` to the second, a
 * same-second pair by lifecycle step (pending < approved < executing <
 * completed/failed/rejected), an unreadable timestamp a tie — the live path
 * then applies the event, and a reseed lets the server row win.
 */
export function compareLiveStatus(
    live: LiveStatusChange,
    other: StatusSnapshot,
): number {
    const liveVersion = live.status_version;
    const otherVersion = other.status_version;

    if (typeof liveVersion === 'number' && typeof otherVersion === 'number') {
        return liveVersion - otherVersion;
    }

    const liveAt = wholeSeconds(live.updated_at);
    const otherAt = wholeSeconds(other.updated_at);

    if (Number.isNaN(liveAt) || Number.isNaN(otherAt)) {
        return 0;
    }

    if (liveAt !== otherAt) {
        return liveAt - otherAt;
    }

    return LIFECYCLE_STEP[live.status] - LIFECYCLE_STEP[other.status];
}

/** Identifies one live change: its version, else its timestamp. */
export function liveChangeKey(live: LiveStatusChange): string {
    return typeof live.status_version === 'number'
        ? `v${live.status_version}`
        : `t${live.updated_at}`;
}

/**
 * The row a reseed should show: the server row, unless the live change is
 * newer — then the row with the live status, version and timestamp, and its
 * result folded in the way a live event folds it (mergeLiveResult()).
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
        status_version: live.status_version ?? row.status_version,
    };
}
