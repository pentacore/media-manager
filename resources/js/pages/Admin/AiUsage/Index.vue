<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AiUsageController from '@/actions/App/Http/Controllers/Admin/AiUsageController';
import UnpricedModelWarning from '@/components/ai/UnpricedModelWarning.vue';
import {
    FreePoolUsage,
    InvocationDetailDialog,
    RateLimitUsage,
    RecentInvocationsTable,
    ScenarioPanel,
    ToolStatsTable,
    UsageAggregateTable,
    UsageHeader,
    UsageStatCards,
} from '@/components/ai-usage';
import type {
    AggregateRow,
    FreePoolRow,
    KindOption,
    PricedModel,
    RateLimitStatusRow,
    RecentRow,
    ScenarioRates,
    TierFilter,
    ToolStatRow,
    Totals,
    WindowKey,
    WindowOption,
} from '@/components/ai-usage';
import { useAiUsageNavigation } from '@/composables/useAiUsageNavigation';
import { useInvocationDetail } from '@/composables/useInvocationDetail';
import { dashboard } from '@/routes';

const props = defineProps<{
    window: WindowKey;
    windows: WindowOption[];
    kind: string | null;
    kinds: KindOption[];
    tier: TierFilter | null;
    tool_stats: ToolStatRow[];
    totals: Totals;
    by_model: AggregateRow[];
    by_provider: AggregateRow[];
    recent: RecentRow[];
    priced_models: PricedModel[];
    scenario: ScenarioRates | null;
    scenario_totals?: Totals;
    scenario_by_model?: AggregateRow[];
    scenario_by_provider?: AggregateRow[];
    scenario_recent?: RecentRow[];
    free_pools: FreePoolRow[];
    rate_limits: RateLimitStatusRow[];
    rate_limits_enforced: boolean;
    unpricedModels: { role: string; provider: string; model: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'AI Usage', href: AiUsageController.index.url() },
        ],
    },
});

const scenarioActive = computed(() => props.scenario !== null);

const { exportUrl, setWindow, setKind, setTier, applyScenario, clearScenario } =
    useAiUsageNavigation(() => ({
        window: props.window,
        kind: props.kind,
        tier: props.tier,
        scenario: props.scenario,
    }));

const {
    detail,
    detailLoading,
    detailError,
    assignKey,
    assigning,
    openDetail,
    closeDetail,
    assignPrice,
} = useInvocationDetail(() => props.scenario);
</script>

<template>
    <Head title="AI Usage" />

    <div class="flex flex-col gap-4 p-5">
        <UsageHeader
            :kind="props.kind"
            :kinds="props.kinds"
            :tier="props.tier"
            :window-key="props.window"
            :windows="props.windows"
            :export-url="exportUrl"
            @kind-change="setKind"
            @tier-change="setTier"
            @window-change="setWindow"
        />

        <UnpricedModelWarning :models="props.unpricedModels" />

        <UsageStatCards
            :totals="totals"
            :window-key="props.window"
            :scenario-active="scenarioActive"
            :scenario-totals="scenario_totals"
        />

        <FreePoolUsage
            v-if="props.free_pools.length > 0"
            :pools="props.free_pools"
        />

        <RateLimitUsage
            v-if="props.rate_limits.length > 0"
            :rows="props.rate_limits"
            :enforced="props.rate_limits_enforced"
        />

        <ScenarioPanel
            :scenario="scenario"
            :priced-models="priced_models"
            @apply="applyScenario"
            @clear="clearScenario"
        />

        <div class="grid gap-4 lg:grid-cols-2">
            <UsageAggregateTable
                title="By model"
                key-heading="Model"
                :rows="by_model"
                :scenario-active="scenarioActive"
                :scenario-rows="scenario_by_model"
                data-usage-by-model
            />
            <UsageAggregateTable
                title="By provider"
                key-heading="Provider"
                :rows="by_provider"
                :scenario-active="scenarioActive"
                :scenario-rows="scenario_by_provider"
                data-usage-by-provider
            />
        </div>

        <ToolStatsTable :rows="props.tool_stats" />

        <RecentInvocationsTable
            :rows="recent"
            :kinds="props.kinds"
            :tier="props.tier"
            :scenario-active="scenarioActive"
            :scenario-rows="scenario_recent"
            @open="openDetail"
        />

        <InvocationDetailDialog
            v-model:assign-key="assignKey"
            :detail="detail"
            :loading="detailLoading"
            :error="detailError"
            :assigning="assigning"
            :priced-models="priced_models"
            @close="closeDetail"
            @assign="assignPrice"
        />
    </div>
</template>
