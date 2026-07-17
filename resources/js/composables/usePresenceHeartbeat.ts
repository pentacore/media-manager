import { onMounted, onUnmounted } from 'vue';
import { heartbeat } from '@/routes';

const HEARTBEAT_INTERVAL_MS = 30_000;
const INTERACTION_WINDOW_MS = 120_000;

const INTERACTION_EVENTS = [
    'mousemove',
    'keydown',
    'touchstart',
    'scroll',
] as const;

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function sendHeartbeat(): Promise<void> {
    try {
        await fetch(heartbeat.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });
    } catch {
        // Network blip — next tick will retry. Heartbeat misses are tolerable
        // because the server-side TTL gives us three intervals of grace.
    }
}

/**
 * Posts to /heartbeat every 30s as long as:
 *   - the tab is visible (document.visibilityState === 'visible'), and
 *   - the user has interacted with the page in the last 2 minutes.
 *
 * The server records the user in a Redis sorted set; the cache warmer
 * skips upstream API calls when the set is empty, so genuinely idle
 * sessions don't drive any background traffic.
 */
export function usePresenceHeartbeat(): void {
    let lastInteractionAt = Date.now();
    let intervalId: ReturnType<typeof setInterval> | null = null;

    const recordInteraction = (): void => {
        lastInteractionAt = Date.now();
    };

    onMounted(() => {
        INTERACTION_EVENTS.forEach((event) => {
            window.addEventListener(event, recordInteraction, {
                passive: true,
            });
        });

        intervalId = setInterval(() => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            if (Date.now() - lastInteractionAt > INTERACTION_WINDOW_MS) {
                return;
            }

            void sendHeartbeat();
        }, HEARTBEAT_INTERVAL_MS);
    });

    onUnmounted(() => {
        if (intervalId !== null) {
            clearInterval(intervalId);
            intervalId = null;
        }

        INTERACTION_EVENTS.forEach((event) => {
            window.removeEventListener(event, recordInteraction);
        });
    });
}
