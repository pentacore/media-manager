import { router } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { ref } from 'vue';
import AiUsageController from '@/actions/App/Http/Controllers/Admin/AiUsageController';
import type { InvocationDetail, ScenarioRates } from '@/components/ai-usage';

export type UseInvocationDetailReturn = {
    detail: Ref<InvocationDetail | null>;
    detailLoading: Ref<boolean>;
    detailError: Ref<string | null>;
    assignKey: Ref<string>;
    assigning: Ref<boolean>;
    openDetail: (row: { id: number }) => Promise<void>;
    closeDetail: () => void;
    assignPrice: () => void;
};

/**
 * The AI usage drill-down: fetches one invocation's detail as JSON (priced
 * under the active scenario, when there is one) and retroactively assigns a
 * catalog price to it, re-fetching the detail afterwards. `scenario` reads the
 * page's current scenario prop.
 */
export function useInvocationDetail(
    scenario: () => ScenarioRates | null,
): UseInvocationDetailReturn {
    const detail = ref<InvocationDetail | null>(null);
    const detailLoading = ref(false);
    const detailError = ref<string | null>(null);
    const assignKey = ref<string>('');
    const assigning = ref(false);

    async function openDetail(row: { id: number }) {
        detail.value = null;
        detailError.value = null;
        detailLoading.value = true;
        assignKey.value = '';

        try {
            const activeScenario = scenario();
            const url = AiUsageController.show.url(
                { aiUsageRecord: row.id },
                activeScenario
                    ? { query: { scenario: { ...activeScenario } } }
                    : {},
            );
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            detail.value = (await response.json()) as InvocationDetail;
        } catch (error) {
            detailError.value =
                error instanceof Error
                    ? error.message
                    : 'Failed to load detail';
        } finally {
            detailLoading.value = false;
        }
    }

    function closeDetail() {
        detail.value = null;
        detailError.value = null;
        assignKey.value = '';
    }

    function assignPrice() {
        if (!detail.value || !assignKey.value) {
            return;
        }

        const [provider, model] = assignKey.value.split('|');
        const recordId = detail.value.record.id;

        assigning.value = true;

        router.post(
            AiUsageController.assignPrice.url({ aiUsageRecord: recordId }),
            { provider, model },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    assigning.value = false;
                    openDetail({ id: recordId });
                },
                onError: () => {
                    assigning.value = false;
                },
            },
        );
    }

    return {
        detail,
        detailLoading,
        detailError,
        assignKey,
        assigning,
        openDetail,
        closeDetail,
        assignPrice,
    };
}
