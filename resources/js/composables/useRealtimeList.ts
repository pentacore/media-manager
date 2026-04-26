import { onUnmounted, ref } from 'vue';
import type { Ref } from 'vue';
import { useWebSocket } from '@/composables/useWebSocket';

export interface UseRealtimeListOptions<T> {
    channel: string;
    event: string;
    keyField: keyof T;
    initial?: T[];
    cap?: number;
    prepend?: boolean;
}

export interface UseRealtimeList<T> {
    items: Ref<T[]>;
    staleCount: Ref<number>;
    isPaused: Ref<boolean>;
    pause: () => void;
    resume: () => void;
    subscribe: () => void;
    unsubscribe: () => void;
}

/**
 * Generic list-of-T realtime channel binding. On each broadcast event,
 * upserts by `keyField` (replace existing OR prepend/append new) and respects
 * an optional `cap`. While paused (e.g. while the page has an active filter
 * the broadcast payload can't be checked against), incoming events bump
 * `staleCount` instead — the page is expected to render a "N new entries"
 * affordance that calls back into a refresh.
 */
export function useRealtimeList<T>({
    channel,
    event,
    keyField,
    initial = [],
    cap,
    prepend = true,
}: UseRealtimeListOptions<T>): UseRealtimeList<T> {
    const { privateChannel, leaveChannel } = useWebSocket();
    const items = ref<T[]>([...initial]) as Ref<T[]>;
    const staleCount = ref(0);
    const isPaused = ref(false);
    let subscribed = false;

    function upsert(payload: T): void {
        const key = payload[keyField];
        const existingIndex = items.value.findIndex(
            (item) => item[keyField] === key,
        );

        if (existingIndex >= 0) {
            items.value[existingIndex] = payload;

            return;
        }

        if (prepend) {
            items.value.unshift(payload);
        } else {
            items.value.push(payload);
        }

        if (cap !== undefined && items.value.length > cap) {
            if (prepend) {
                items.value.length = cap;
            } else {
                items.value.shift();
            }
        }
    }

    function subscribe(): void {
        if (subscribed) {
            return;
        }

        privateChannel(channel).listen(`.${event}`, (payload: T) => {
            if (isPaused.value) {
                staleCount.value += 1;

                return;
            }

            upsert(payload);
        });

        subscribed = true;
    }

    function unsubscribe(): void {
        if (!subscribed) {
            return;
        }

        leaveChannel(channel);
        subscribed = false;
    }

    function pause(): void {
        isPaused.value = true;
    }

    function resume(): void {
        isPaused.value = false;
        staleCount.value = 0;
    }

    onUnmounted(unsubscribe);

    return { items, staleCount, isPaused, pause, resume, subscribe, unsubscribe };
}
