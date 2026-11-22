<script setup lang="ts">
import { formatCount } from '@/lib/format';
import { formatCost, formatRate } from './format';
import type { InvocationDetail } from './types';

defineProps<{
    detail: InvocationDetail;
}>();
</script>

<template>
    <div
        class="overflow-hidden rounded-md border border-border"
        data-usage-breakdown
    >
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-[12px]">
                <thead>
                    <tr class="bg-bg-elev">
                        <th
                            class="px-3 py-2 text-left font-medium text-muted-foreground max-sm:px-2"
                        >
                            Component
                        </th>
                        <th
                            class="px-3 py-2 text-right font-medium text-muted-foreground max-sm:px-2"
                        >
                            Tokens
                        </th>
                        <th
                            class="px-3 py-2 text-right font-medium text-muted-foreground max-sm:px-2"
                        >
                            Rate / 1M
                        </th>
                        <th
                            class="px-3 py-2 text-right font-medium text-muted-foreground max-sm:px-2"
                        >
                            Cost
                        </th>
                        <th
                            v-if="detail.scenario_breakdown"
                            class="px-3 py-2 text-right font-medium text-muted-foreground max-sm:px-2"
                        >
                            Scenario
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(line, idx) in detail.breakdown"
                        :key="line.label"
                        class="border-t border-border"
                    >
                        <td class="px-3 py-2 max-sm:px-2">
                            {{ line.label }}
                        </td>
                        <td
                            class="font-mono-tabular px-3 py-2 text-right max-sm:px-2"
                        >
                            {{ formatCount(line.tokens) }}
                        </td>
                        <td
                            class="font-mono-tabular px-3 py-2 text-right max-sm:px-2"
                        >
                            {{ formatRate(line.rate) }}
                        </td>
                        <td
                            class="font-mono-tabular px-3 py-2 text-right max-sm:px-2"
                        >
                            {{ formatCost(line.cost) }}
                        </td>
                        <td
                            v-if="detail.scenario_breakdown"
                            class="font-mono-tabular px-3 py-2 text-right text-accent max-sm:px-2"
                        >
                            {{
                                formatCost(detail.scenario_breakdown[idx].cost)
                            }}
                        </td>
                    </tr>
                    <tr
                        class="border-t-2 border-border bg-bg-elev font-semibold"
                    >
                        <td class="px-3 py-2 max-sm:px-2" colspan="3">Total</td>
                        <td
                            class="font-mono-tabular px-3 py-2 text-right max-sm:px-2"
                        >
                            {{ formatCost(detail.total_cost) }}
                        </td>
                        <td
                            v-if="detail.scenario_total_cost !== null"
                            class="font-mono-tabular px-3 py-2 text-right text-accent max-sm:px-2"
                        >
                            {{ formatCost(detail.scenario_total_cost) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
