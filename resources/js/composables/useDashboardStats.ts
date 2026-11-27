import { router } from '@inertiajs/vue3';
import { ref, onUnmounted } from 'vue';
import type { Ref } from 'vue';
import { useWebSocket } from '@/composables/useWebSocket';
import type { ChannelLease } from '@/composables/useWebSocket';

export interface DashboardStats {
    activeServices: number;
    totalServices: number;
    healthyServices: number;
    recentWebhooks: number;
    pendingActions: number;
    recentActions: number;
    failedActions: number;
    updatedAt: string;
}

export interface UseDashboardStats {
    stats: Ref<DashboardStats | null>;
    subscribe: () => void;
    unsubscribe: () => void;
}

/**
 * The dashboard's stat counts as `.DashboardStatsUpdated` pushes them; null
 * until the first one, so the page shows its `stats` prop. When a dropped
 * connection comes back, the pushed counts are dropped and the prop reloaded,
 * since updates sent meanwhile were lost.
 */
export function useDashboardStats(): UseDashboardStats {
    const { acquirePrivateChannel, onReconnect } = useWebSocket();
    const stats = ref<DashboardStats | null>(null);
    let lease: ChannelLease | null = null;
    let stopReconnect: (() => void) | null = null;

    function subscribe(): void {
        if (lease) {
            return;
        }

        lease = acquirePrivateChannel('dashboard').listen(
            '.DashboardStatsUpdated',
            (event: DashboardStats) => {
                stats.value = event;
            },
        );

        stopReconnect = onReconnect(() => {
            stats.value = null;
            router.reload({ only: ['stats'] });
        });
    }

    function unsubscribe(): void {
        lease?.release();
        lease = null;
        stopReconnect?.();
        stopReconnect = null;
    }

    onUnmounted(unsubscribe);

    return { stats, subscribe, unsubscribe };
}
