<script setup lang="ts">
import { StatCard } from '@/components/mm';
import { formatCount } from '@/lib/format';
import { costDelta, formatCost } from './format';
import type { Totals, WindowKey } from './types';

defineProps<{
    totals: Totals;
    windowKey: WindowKey;
    scenarioActive: boolean;
    scenarioTotals?: Totals;
}>();
</script>

<template>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4" data-usage-stats>
        <StatCard
            label="Spend"
            :value="formatCost(totals.total_cost)"
            :hint="
                scenarioActive && scenarioTotals
                    ? `${formatCost(scenarioTotals.total_cost)} projected · ${costDelta(totals.total_cost, scenarioTotals.total_cost)}`
                    : `${windowKey} window`
            "
        />
        <StatCard
            label="Invocations"
            :value="formatCount(totals.total_invocations)"
            :hint="`${formatCount(totals.total_tool_calls)} tool calls`"
        />
        <StatCard
            label="Total tokens"
            :value="formatCount(totals.total_tokens)"
            hint="prompt + completion"
        />
        <StatCard
            label="Tool calls"
            :value="formatCount(totals.total_tool_calls)"
            hint="across all invocations"
        />
    </div>
</template>
