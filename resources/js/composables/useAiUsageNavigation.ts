import { router } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';
import AiUsageController from '@/actions/App/Http/Controllers/Admin/AiUsageController';
import type {
    ScenarioRates,
    TierFilter,
    WindowKey,
} from '@/components/ai-usage';
import type { QueryParams } from '@/wayfinder';

export type AiUsageFilterState = {
    window: WindowKey;
    kind: string | null;
    tier: TierFilter | null;
    scenario: ScenarioRates | null;
};

export type UseAiUsageNavigationReturn = {
    exportUrl: ComputedRef<string>;
    setWindow: (value: string) => void;
    setKind: (value: string) => void;
    setTier: (value: string) => void;
    applyScenario: (rates: ScenarioRates) => void;
    clearScenario: () => void;
};

/**
 * The AI usage page's visits: switching the time window, kind or tier filter,
 * applying or clearing a what-if scenario, and the CSV export URL. Each keeps
 * the filters it does not change. `state` reads the page's current props.
 */
export function useAiUsageNavigation(
    state: () => AiUsageFilterState,
): UseAiUsageNavigationReturn {
    function setWindow(value: string) {
        router.visit(
            AiUsageController.index.url({
                query: buildQuery({ window: value }),
            }),
            { preserveScroll: true, preserveState: true },
        );
    }

    function setKind(value: string) {
        router.get(
            AiUsageController.index.url({
                query: buildQuery({
                    window: state().window,
                    kind: value === 'all' ? undefined : value,
                }),
            }),
            {},
            { preserveScroll: true },
        );
    }

    function setTier(value: string) {
        router.get(
            AiUsageController.index.url({
                query: buildQuery({
                    window: state().window,
                    tier: value === 'all' ? undefined : value,
                }),
            }),
            {},
            { preserveScroll: true },
        );
    }

    const exportUrl = computed(() =>
        AiUsageController.exportMethod.url({
            query: buildQuery({ window: state().window }),
        }),
    );

    /**
     * Query for a visit that keeps the active kind and tier filters and the
     * scenario. Pass `kind: undefined` or `tier: undefined` explicitly to drop
     * one.
     */
    function buildQuery(
        extra: Record<string, string | undefined>,
    ): QueryParams {
        const { kind, tier, scenario } = state();
        const merged: Record<string, string | undefined> = {
            kind: kind ?? undefined,
            tier: tier ?? undefined,
            ...extra,
        };
        const query: QueryParams = {};

        for (const [key, value] of Object.entries(merged)) {
            if (value !== undefined) {
                query[key] = value;
            }
        }

        if (scenario) {
            query.scenario = { ...scenario };
        }

        return query;
    }

    function applyScenario(rates: ScenarioRates) {
        const { window: windowKey, kind, tier } = state();

        router.visit(
            AiUsageController.index.url({
                query: {
                    window: windowKey,
                    ...(kind ? { kind } : {}),
                    ...(tier ? { tier } : {}),
                    scenario: {
                        input: rates.input,
                        output: rates.output,
                        cache_read: rates.cache_read,
                        cache_write: rates.cache_write,
                        reasoning: rates.reasoning,
                    },
                },
            }),
            { preserveScroll: true },
        );
    }

    function clearScenario() {
        const { window: windowKey, kind, tier } = state();

        router.visit(
            AiUsageController.index.url({
                query: {
                    window: windowKey,
                    ...(kind ? { kind } : {}),
                    ...(tier ? { tier } : {}),
                },
            }),
            { preserveScroll: true },
        );
    }

    return {
        exportUrl,
        setWindow,
        setKind,
        setTier,
        applyScenario,
        clearScenario,
    };
}
