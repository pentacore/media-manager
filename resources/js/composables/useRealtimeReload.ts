import { router } from '@inertiajs/vue3';
import { onUnmounted } from 'vue';
import { useWebSocket } from '@/composables/useWebSocket';

export interface UseRealtimeReloadOptions<TEvent> {
    channel: string;
    event: string;
    only: string[];
    debounceMs?: number;
    filter?: (event: TEvent) => boolean;
}

export interface UseRealtimeReload {
    subscribe: () => void;
    unsubscribe: () => void;
}

/**
 * Subscribes to a private channel event and triggers a debounced
 * `router.reload({ only })` whenever the event fires (and passes the optional
 * filter). Used when the broadcast payload doesn't match the SSR shape — for
 * example list pages with server-side joins, search filters, or pagination
 * — so the simplest correct response is to refetch the prop.
 */
export function useRealtimeReload<TEvent>({
    channel,
    event,
    only,
    debounceMs = 1500,
    filter,
}: UseRealtimeReloadOptions<TEvent>): UseRealtimeReload {
    const { privateChannel, leaveChannel } = useWebSocket();
    let subscribed = false;
    let reloadTimer: ReturnType<typeof setTimeout> | null = null;
    let reloading = false;

    function scheduleReload(): void {
        if (reloadTimer || reloading) {
            return;
        }

        reloadTimer = setTimeout(() => {
            reloadTimer = null;
            reloading = true;
            router.reload({
                only,
                onFinish: () => {
                    reloading = false;
                },
            });
        }, debounceMs);
    }

    function subscribe(): void {
        if (subscribed) {
            return;
        }

        privateChannel(channel).listen(`.${event}`, (payload: TEvent) => {
            if (filter && !filter(payload)) {
                return;
            }

            scheduleReload();
        });

        subscribed = true;
    }

    function unsubscribe(): void {
        if (reloadTimer) {
            clearTimeout(reloadTimer);
            reloadTimer = null;
        }

        if (!subscribed) {
            return;
        }

        leaveChannel(channel);
        subscribed = false;
    }

    onUnmounted(unsubscribe);

    return { subscribe, unsubscribe };
}
