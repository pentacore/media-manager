import { router } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { onUnmounted, ref } from 'vue';
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
 * page's current scenario prop. Only the newest request counts: opening
 * another row or closing the dialog aborts the one in flight, so the dialog
 * can be closed while it loads and a late answer never reopens or
 * overwrites it.
 *
 * A price assignment that lands after the dialog was closed or moved on to
 * another run leaves it as it is.
 */
export function useInvocationDetail(
    scenario: () => ScenarioRates | null,
): UseInvocationDetailReturn {
    const detail = ref<InvocationDetail | null>(null);
    const detailLoading = ref(false);
    const detailError = ref<string | null>(null);
    const assignKey = ref<string>('');
    const assigning = ref(false);
    let detailRequest: AbortController | null = null;
    /** The run the dialog shows or is loading; null while it is closed. */
    let shownRecordId: number | null = null;

    function abortDetailRequest(): void {
        detailRequest?.abort();
        detailRequest = null;
    }

    async function openDetail(row: { id: number }) {
        abortDetailRequest();
        shownRecordId = row.id;
        const controller = new AbortController();
        detailRequest = controller;

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
                signal: controller.signal,
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const loaded = (await response.json()) as InvocationDetail;

            if (!controller.signal.aborted) {
                detail.value = loaded;
            }
        } catch (error) {
            // Closed or replaced while loading: nothing to show.
            if (controller.signal.aborted) {
                return;
            }

            detailError.value =
                error instanceof Error
                    ? error.message
                    : 'Failed to load detail';
        } finally {
            if (detailRequest === controller) {
                detailRequest = null;
                detailLoading.value = false;
            }
        }
    }

    function closeDetail() {
        abortDetailRequest();
        shownRecordId = null;
        detailLoading.value = false;
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

                    // Re-read the run only while the dialog still shows it:
                    // the admin may have closed it, or opened another run,
                    // while the assignment saved.
                    if (shownRecordId === recordId) {
                        openDetail({ id: recordId });
                    }
                },
                onError: () => {
                    assigning.value = false;
                },
            },
        );
    }

    onUnmounted(abortDetailRequest);

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
