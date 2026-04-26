import { usePage } from '@inertiajs/vue3';
import { onUnmounted } from 'vue';
import { toast } from 'vue-sonner';
import { useWebSocket } from '@/composables/useWebSocket';

export interface UseNotifications {
    subscribe: () => void;
    unsubscribe: () => void;
}

export function useNotifications(): UseNotifications {
    const { privateChannel, leaveChannel } = useWebSocket();
    const page = usePage();
    let channelName: string | null = null;

    function resolveChannel(): string | null {
        const userId = page.props.auth?.user?.id;

        if (typeof userId !== 'number') {
            return null;
        }

        return `App.Models.User.${userId}`;
    }

    function subscribe(): void {
        if (channelName) {
            return;
        }

        const name = resolveChannel();

        if (!name) {
            return;
        }

        channelName = name;

        privateChannel(name)
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
        if (channelName) {
            leaveChannel(channelName);
            channelName = null;
        }
    }

    onUnmounted(unsubscribe);

    return { subscribe, unsubscribe };
}
