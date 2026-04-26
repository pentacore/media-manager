import { onUnmounted } from 'vue';
import { toast } from 'vue-sonner';
import { useWebSocket } from '@/composables/useWebSocket';

const CHANNEL_NAME = 'members.actions';

export interface UseNotifications {
    subscribe: () => void;
    unsubscribe: () => void;
}

export function useNotifications(): UseNotifications {
    const { privateChannel, leaveChannel } = useWebSocket();
    let subscribed = false;

    function subscribe(): void {
        if (subscribed) {
            return;
        }

        subscribed = true;

        privateChannel(CHANNEL_NAME)
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
        if (subscribed) {
            leaveChannel(CHANNEL_NAME);
            subscribed = false;
        }
    }

    onUnmounted(unsubscribe);

    return { subscribe, unsubscribe };
}
