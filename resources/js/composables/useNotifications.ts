import { onUnmounted } from 'vue';
import { toast } from 'vue-sonner';
import { useWebSocket } from '@/composables/useWebSocket';
import type { ChannelLease } from '@/composables/useWebSocket';

const CHANNEL_NAME = 'members.actions';

export interface UseNotifications {
    subscribe: () => void;
    unsubscribe: () => void;
}

export function useNotifications(): UseNotifications {
    const { acquirePrivateChannel } = useWebSocket();
    let lease: ChannelLease | null = null;

    function subscribe(): void {
        if (lease) {
            return;
        }

        lease = acquirePrivateChannel(CHANNEL_NAME)
            .listen(
                '.ActionRequestCreated',
                (event: Record<string, unknown>) => {
                    toast.info('New Action Request', {
                        description: `${event.type} from ${event.source_service} → ${event.target_service}`,
                    });
                },
            )
            .listen(
                '.ActionRequestStatusChanged',
                (event: Record<string, unknown>) => {
                    const status = event.status as string;
                    const toastFn =
                        status === 'completed'
                            ? toast.success
                            : status === 'failed'
                              ? toast.error
                              : toast.info;
                    toastFn('Action Request Updated', {
                        description: `Request #${event.id} is now ${status}`,
                    });
                },
            );
    }

    function unsubscribe(): void {
        lease?.release();
        lease = null;
    }

    onUnmounted(unsubscribe);

    return { subscribe, unsubscribe };
}
