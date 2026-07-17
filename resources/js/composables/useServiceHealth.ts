import { onUnmounted, reactive } from 'vue';
import { useWebSocket } from '@/composables/useWebSocket';
import type { ChannelLease } from '@/composables/useWebSocket';

export interface ServiceStatus {
    id: number;
    name: string;
    type: string;
    is_active: boolean;
    status: string;
    message: string | null;
    last_seen_at: string | null;
}

export interface ServiceVersionInfo {
    id: number;
    version: string | null;
    latest_version: string | null;
    update_available: boolean;
}

export interface ServiceLifecycleSnapshot {
    id: number;
    type: string;
    name: string;
    url: string;
    is_active: boolean;
    health_status: string;
    health_message: string | null;
    version: string | null;
    latest_version: string | null;
    update_available: boolean;
    last_seen_at: string | null;
}

export interface UseServiceHealth {
    services: Record<number, ServiceStatus>;
    versions: Record<number, ServiceVersionInfo>;
    lifecycle: Record<number, ServiceLifecycleSnapshot>;
    deletedIds: Set<number>;
    subscribe: () => void;
    unsubscribe: () => void;
}

export function useServiceHealth(): UseServiceHealth {
    const { acquirePrivateChannel } = useWebSocket();
    const services = reactive<Record<number, ServiceStatus>>({});
    const versions = reactive<Record<number, ServiceVersionInfo>>({});
    const lifecycle = reactive<Record<number, ServiceLifecycleSnapshot>>({});
    const deletedIds = reactive<Set<number>>(new Set());

    let lease: ChannelLease | null = null;

    function subscribe(): void {
        // Same guard as the sibling composables: a second call would stack
        // duplicate listeners on the shared channel.
        if (lease) {
            return;
        }

        lease = acquirePrivateChannel('services')
            .listen('.ServiceHealthChanged', (event: ServiceStatus) => {
                services[event.id] = event;
            })
            .listen(
                '.ServiceLatestVersionFetched',
                (event: ServiceVersionInfo) => {
                    versions[event.id] = event;
                },
            )
            .listen(
                '.ServiceConnectionUpserted',
                (event: ServiceLifecycleSnapshot) => {
                    lifecycle[event.id] = event;
                    deletedIds.delete(event.id);
                },
            )
            .listen('.ServiceConnectionDeleted', (event: { id: number }) => {
                deletedIds.add(event.id);
                delete services[event.id];
                delete versions[event.id];
                delete lifecycle[event.id];
            });
    }

    function unsubscribe(): void {
        lease?.release();
        lease = null;
    }

    onUnmounted(unsubscribe);

    return {
        services,
        versions,
        lifecycle,
        deletedIds,
        subscribe,
        unsubscribe,
    };
}
