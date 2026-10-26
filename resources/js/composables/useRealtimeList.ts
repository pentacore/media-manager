import { onUnmounted, ref } from 'vue';
import type { Ref } from 'vue';
import { useWebSocket } from '@/composables/useWebSocket';
import type { ChannelLease } from '@/composables/useWebSocket';

export interface UseRealtimeListOptions<T, TPayload = T> {
    /** One private channel, or several that feed the same list. */
    channel: string | readonly string[];
    event: string;
    keyField: keyof T;
    initial?: T[];
    cap?: number;
    prepend?: boolean;
    /**
     * Turns a broadcast payload into a list row. Needed when the page's row
     * carries a per-reader field (a permission) that a broadcast shared by
     * every reader cannot: derive it here, from what the payload does say.
     */
    map?: (payload: TPayload) => T;
    /**
     * Whether an event for a row already listed replaces it (default:
     * always). A list whose rows carry an order passes its rule here, so a
     * broadcast delivered late cannot roll a newer row back. A row not yet
     * listed is always added.
     */
    replaces?: (current: T, incoming: T) => boolean;
    /**
     * Whether an event that arrives while paused counts toward `staleCount`
     * (default: always). A list paused on a view that no event can ever join
     * (a batch's own rows) returns false, so no "N new" notice is raised.
     */
    countsAsStale?: (payload: TPayload) => boolean;
}

export interface UseRealtimeList<T> {
    items: Ref<T[]>;
    staleCount: Ref<number>;
    isPaused: Ref<boolean>;
    pause: () => void;
    resume: () => void;
    reseed: (rows: T[]) => void;
    subscribe: () => void;
    unsubscribe: () => void;
}

/**
 * Generic list-of-T realtime channel binding. On each broadcast event (from
 * any of its channels), upserts by `keyField` (replace existing OR
 * prepend/append new) and respects an optional `cap`. A row that arrives on
 * two channels is stored once. While paused (e.g. while the page has an
 * active filter the broadcast payload can't be checked against), incoming
 * events bump `staleCount` instead — the page is expected to render a
 * "N new entries" affordance that calls back into a refresh.
 */
export function useRealtimeList<T, TPayload = T>({
    channel,
    event,
    keyField,
    initial = [],
    cap,
    prepend = true,
    map,
    replaces,
    countsAsStale,
}: UseRealtimeListOptions<T, TPayload>): UseRealtimeList<T> {
    const { acquirePrivateChannel } = useWebSocket();
    const channels: string[] =
        typeof channel === 'string' ? [channel] : [...channel];
    const items = ref<T[]>([...initial]) as Ref<T[]>;
    const staleCount = ref(0);
    const isPaused = ref(false);
    let leases: ChannelLease[] = [];

    function upsert(row: T): void {
        const key = row[keyField];
        const existingIndex = items.value.findIndex(
            (item) => item[keyField] === key,
        );

        if (existingIndex >= 0) {
            if (replaces && !replaces(items.value[existingIndex], row)) {
                return;
            }

            items.value[existingIndex] = row;

            return;
        }

        if (prepend) {
            items.value.unshift(row);
        } else {
            items.value.push(row);
        }

        if (cap !== undefined && items.value.length > cap) {
            if (prepend) {
                items.value.length = cap;
            } else {
                items.value.shift();
            }
        }
    }

    function receive(payload: TPayload): void {
        if (isPaused.value) {
            if (countsAsStale === undefined || countsAsStale(payload)) {
                staleCount.value += 1;
            }

            return;
        }

        upsert(map ? map(payload) : (payload as unknown as T));
    }

    function subscribe(): void {
        if (leases.length > 0) {
            return;
        }

        leases = channels.map((name) =>
            acquirePrivateChannel(name).listen(`.${event}`, receive),
        );
    }

    function unsubscribe(): void {
        leases.forEach((lease) => lease.release());
        leases = [];
    }

    function pause(): void {
        isPaused.value = true;
    }

    function resume(): void {
        isPaused.value = false;
        staleCount.value = 0;
    }

    /**
     * Replace the list with fresh server rows. Pages navigate with
     * `preserveState: true`, so the once-at-setup `initial` seed goes stale
     * the moment a filter changes or a reload replaces the prop — callers
     * must reseed from a watcher on the prop or the realtime list renders
     * rows from a previous filter/page.
     */
    function reseed(rows: T[]): void {
        items.value = [...rows];
        staleCount.value = 0;
    }

    onUnmounted(unsubscribe);

    return {
        items,
        staleCount,
        isPaused,
        pause,
        resume,
        reseed,
        subscribe,
        unsubscribe,
    };
}
