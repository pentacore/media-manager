import { onUnmounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { useWebSocket } from '@/composables/useWebSocket';

export interface UseNotifications {
    subscribe: () => void;
    unsubscribe: () => void;
}

export function useNotifications(): UseNotifications {
    const { privateChannel, leaveChannel } = useWebSocket();
    const page = usePage();
    let channelName = '';

    function subscribe(): void {
        const userId = page.props.auth.user?.id;
        if (!userId) {
            return;
        }

        channelName = `App.Models.User.${userId}`;

        privateChannel(channelName)
            .listen('.ActionRequestCreated', (event: Record<string, unknown>) => {
                toast.info('New Action Request', {
                    description: `${event.type} from ${event.source_service} → ${event.target_service}`,
                });
            })
            .listen('.ActionRequestStatusChanged', (event: Record<string, unknown>) => {
                const status = event.status as string;
                const toastFn = status === 'completed' ? toast.success : status === 'failed' ? toast.error : toast.info;
                toastFn('Action Request Updated', {
                    description: `Request #${event.id} is now ${status}`,
                });
            });
    }

    function unsubscribe(): void {
        if (channelName) {
            leaveChannel(channelName);
            channelName = '';
        }
    }

    onUnmounted(unsubscribe);

    return { subscribe, unsubscribe };
}
