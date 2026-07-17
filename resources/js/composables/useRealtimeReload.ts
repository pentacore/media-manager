import { router } from '@inertiajs/vue3';
import { onUnmounted } from 'vue';
import { useWebSocket } from '@/composables/useWebSocket';
import type { ChannelLease } from '@/composables/useWebSocket';

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
    const { acquirePrivateChannel } = useWebSocket();
    let lease: ChannelLease | null = null;
    let reloadTimer: ReturnType<typeof setTimeout> | null = null;
    let reloading = false;
    let dirtyDuringReload = false;

    function scheduleReload(): void {
        // An event landing while a reload is in flight must not be dropped —
        // the running reload's response may predate the event's write. Flag
        // it and schedule a follow-up reload when the current one finishes.
        if (reloading) {
            dirtyDuringReload = true;

            return;
        }

        if (reloadTimer) {
            return;
        }

        reloadTimer = setTimeout(() => {
            reloadTimer = null;
            reloading = true;
            router.reload({
                only,
                onFinish: () => {
                    reloading = false;

                    if (dirtyDuringReload) {
                        dirtyDuringReload = false;
                        scheduleReload();
                    }
                },
            });
        }, debounceMs);
    }

    function subscribe(): void {
        if (lease) {
            return;
        }

        lease = acquirePrivateChannel(channel).listen(
            `.${event}`,
            (payload: TEvent) => {
                if (filter && !filter(payload)) {
                    return;
                }

                scheduleReload();
            },
        );
    }

    function unsubscribe(): void {
        if (reloadTimer) {
            clearTimeout(reloadTimer);
            reloadTimer = null;
        }

        lease?.release();
        lease = null;
    }

    onUnmounted(unsubscribe);

    return { subscribe, unsubscribe };
}
