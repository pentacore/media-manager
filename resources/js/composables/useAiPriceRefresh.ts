import { router } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { onUnmounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import AiModelPriceController from '@/actions/App/Http/Controllers/Admin/AiModelPriceController';
import { useWebSocket } from '@/composables/useWebSocket';
import type { ChannelLease } from '@/composables/useWebSocket';

const PRICE_REFRESH_CHANNEL = 'admin.ai-prices';

interface PriceRefreshPayload {
    state: 'queued' | 'running' | 'succeeded' | 'failed';
    triggered_by: { id: number; name: string } | null;
    summary: string | null;
    error: string | null;
    added: number | null;
    total: number | null;
    occurred_at: string;
    run_id: number | null;
    final_result: 'succeeded' | 'partial' | 'failed' | null;
    models_dev_status: string | null;
    providers_requested: number | null;
    providers_succeeded: number | null;
    providers_failed: number | null;
    models_created: number | null;
    models_updated: number | null;
    models_unchanged: number | null;
    models_locked: number | null;
    models_rejected: number | null;
    models_tiered: number | null;
    fallback_providers: string[] | null;
    error_message: string | null;
}

export type UseAiPriceRefreshReturn = {
    refreshing: Ref<boolean>;
    refreshPrices: () => void;
    subscribe: () => void;
    unsubscribe: () => void;
};

/**
 * The AI prices "Refresh online" state. `refreshing` starts from the server's
 * lock flag, flips on as soon as the admin clicks, and then follows the
 * `.AiPriceRefreshStateChanged` broadcasts on the private `admin.ai-prices`
 * channel, which also toast the outcome and reload the price rows. Call
 * `subscribe()` from the page's `onMounted`; the lease is released on unmount.
 */
export function useAiPriceRefresh(
    initiallyRunning: boolean,
): UseAiPriceRefreshReturn {
    const refreshing = ref(initiallyRunning);
    const { acquirePrivateChannel } = useWebSocket();
    let refreshLease: ChannelLease | null = null;

    function refreshPrices() {
        if (refreshing.value) {
            return;
        }

        // Optimistically flip the button so admins get instant feedback even
        // before the broadcast lands. The job will keep us in this state until
        // succeeded/failed arrives.
        refreshing.value = true;
        router.post(
            AiModelPriceController.refresh.url(),
            {},
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    }

    /**
     * Compact created/updated/locked/rejected counter string for the enriched
     * refresh toasts.
     */
    function refreshCounts(payload: PriceRefreshPayload): string {
        return [
            `${payload.models_created ?? 0} created`,
            `${payload.models_updated ?? 0} updated`,
            `${payload.models_locked ?? 0} locked`,
            `${payload.models_rejected ?? 0} rejected`,
        ].join(', ');
    }

    function handleRefreshState(payload: PriceRefreshPayload): void {
        if (payload.state === 'queued' || payload.state === 'running') {
            refreshing.value = true;

            return;
        }

        refreshing.value = false;

        // Enriched runs carry a final_result; legacy payloads leave it null and
        // keep the original added/total success behavior below.
        if (payload.final_result !== null) {
            if (payload.final_result === 'succeeded') {
                toast.success('Price refresh complete', {
                    description: `${refreshCounts(payload)}.`,
                });
                router.reload({ only: ['prices'] });

                return;
            }

            if (payload.final_result === 'partial') {
                const fallback =
                    payload.fallback_providers &&
                    payload.fallback_providers.length > 0
                        ? ` Fallback: ${payload.fallback_providers.join(', ')}.`
                        : '';
                toast.warning('Price refresh partially completed', {
                    description: `${refreshCounts(payload)}.${fallback}`,
                });
                router.reload({ only: ['prices'] });

                return;
            }

            toast.error('Price refresh failed', {
                description:
                    payload.error_message ?? payload.error ?? 'Unknown error',
            });

            return;
        }

        if (payload.state === 'succeeded') {
            const triggered = payload.triggered_by
                ? ` triggered by ${payload.triggered_by.name}`
                : '';
            toast.success('Price refresh complete', {
                description: `${payload.added ?? 0} new, ${payload.total ?? 0} total${triggered}.`,
            });
            router.reload({ only: ['prices'] });

            return;
        }

        toast.error('Price refresh failed', {
            description: payload.error ?? 'Unknown error',
        });
    }

    function subscribe(): void {
        if (refreshLease) {
            return;
        }

        refreshLease = acquirePrivateChannel(PRICE_REFRESH_CHANNEL).listen(
            '.AiPriceRefreshStateChanged',
            (event: PriceRefreshPayload) => handleRefreshState(event),
        );
    }

    function unsubscribe(): void {
        refreshLease?.release();
        refreshLease = null;
    }

    onUnmounted(unsubscribe);

    return { refreshing, refreshPrices, subscribe, unsubscribe };
}
