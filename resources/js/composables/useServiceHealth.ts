import { reactive, onUnmounted } from 'vue';
import { useWebSocket } from '@/composables/useWebSocket';

export interface ServiceStatus {
    id: number;
    name: string;
    type: string;
    is_active: boolean;
    status: string;
    message: string | null;
    last_seen_at: string | null;
}

export interface UseServiceHealth {
    services: Record<number, ServiceStatus>;
    subscribe: () => void;
    unsubscribe: () => void;
}

export function useServiceHealth(): UseServiceHealth {
    const { privateChannel, leaveChannel } = useWebSocket();
    const services = reactive<Record<number, ServiceStatus>>({});

    function subscribe(): void {
        privateChannel('services').listen(
            '.ServiceHealthChanged',
            (event: ServiceStatus) => {
                services[event.id] = event;
            },
        );
    }

    function unsubscribe(): void {
        leaveChannel('services');
    }

    onUnmounted(unsubscribe);

    return { services, subscribe, unsubscribe };
}
