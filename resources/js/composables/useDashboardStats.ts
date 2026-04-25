import { ref, onUnmounted } from 'vue';
import type { Ref } from 'vue';
import { useWebSocket } from '@/composables/useWebSocket';

export interface DashboardStats {
    activeServices: number;
    totalServices: number;
    recentWebhooks: number;
    pendingActions: number;
    updatedAt: string;
}

export interface UseDashboardStats {
    stats: Ref<DashboardStats | null>;
    subscribe: () => void;
    unsubscribe: () => void;
}

export function useDashboardStats(): UseDashboardStats {
    const { privateChannel, leaveChannel } = useWebSocket();
    const stats = ref<DashboardStats | null>(null);

    function subscribe(): void {
        privateChannel('dashboard').listen(
            '.DashboardStatsUpdated',
            (event: DashboardStats) => {
                stats.value = event;
            },
        );
    }

    function unsubscribe(): void {
        leaveChannel('dashboard');
    }

    onUnmounted(unsubscribe);

    return { stats, subscribe, unsubscribe };
}
