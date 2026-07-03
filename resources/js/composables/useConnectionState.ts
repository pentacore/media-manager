import { onMounted, onUnmounted, ref } from 'vue';
import type { Ref } from 'vue';
import { useWebSocket } from '@/composables/useWebSocket';

export type ConnectionState =
    'connected' | 'connecting' | 'disconnected' | 'unavailable';

interface PusherConnection {
    state: string;
    bind: (event: string, callback: (payload: unknown) => void) => void;
    unbind: (event: string, callback: (payload: unknown) => void) => void;
}

interface PusherLike {
    connection: PusherConnection;
}

interface ConnectorWithPusher {
    pusher?: PusherLike;
}

/**
 * Exposes the underlying Pusher/Reverb connection lifecycle as a reactive ref.
 * Used by the sidebar header indicator so the user can tell at a glance when
 * realtime is offline or reconnecting.
 */
export function useConnectionState(): { state: Ref<ConnectionState> } {
    const { echo } = useWebSocket();
    const state = ref<ConnectionState>('connecting');

    let listener: ((payload: unknown) => void) | null = null;
    let connection: PusherConnection | null = null;

    function normalize(raw: string): ConnectionState {
        if (raw === 'connected') {
            return 'connected';
        }

        if (raw === 'connecting' || raw === 'initialized') {
            return 'connecting';
        }

        if (raw === 'unavailable' || raw === 'failed') {
            return 'unavailable';
        }

        return 'disconnected';
    }

    onMounted(() => {
        const connector = echo().connector as unknown as ConnectorWithPusher;

        if (!connector.pusher) {
            return;
        }

        connection = connector.pusher.connection;
        state.value = normalize(connection.state);

        listener = (payload: unknown) => {
            const next = (payload as { current?: string } | undefined)?.current;

            if (typeof next === 'string') {
                state.value = normalize(next);
            }
        };

        connection.bind('state_change', listener);
    });

    onUnmounted(() => {
        if (connection && listener) {
            connection.unbind('state_change', listener);
        }
    });

    return { state };
}
