import { onUnmounted } from 'vue';
import { toast } from 'vue-sonner';
import { useWebSocket } from '@/composables/useWebSocket';

export interface UseNotifications {
    subscribe: () => void;
    unsubscribe: () => void;
}

export function useNotifications(): UseNotifications {
    const { privateChannel, leaveChannel } = useWebSocket();
    const channelName = 'dashboard';
    let subscribed = false;

    function subscribe(): void {
        if (subscribed) {
            return;
        }

        privateChannel(channelName)
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

        subscribed = true;
    }

    function unsubscribe(): void {
        if (subscribed) {
            leaveChannel(channelName);
            subscribed = false;
        }
    }

    onUnmounted(unsubscribe);

    return { subscribe, unsubscribe };
}
