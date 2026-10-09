<script setup lang="ts">
import { computed } from 'vue';
import { formatCost, formatNumber } from './format';
import type { AggregateRow } from './types';

/**
 * One "By model" / "By provider" card. With a scenario active it adds a
 * Projected column, looked up by row key in the scenario's rows.
 */
const props = defineProps<{
    title: string;
    keyHeading: string;
    rows: AggregateRow[];
    scenarioActive: boolean;
    scenarioRows?: AggregateRow[];
}>();

const projected = computed(() => indexByKey(props.scenarioRows ?? []));

function indexByKey(rows: AggregateRow[]): Record<string, string> {
    return Object.fromEntries(
        rows.map((row) => [row.key ?? '__null__', row.total_cost] as const),
    );
}
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-border bg-card">
        <div
            class="border-b border-border px-4 py-3 text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
        >
            {{ title }}
        </div>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            class="border-b border-border px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            {{ keyHeading }}
                        </th>
                        <th
                            class="border-b border-border px-3 py-2 text-right text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            Calls
                        </th>
                        <th
                            class="border-b border-border px-3 py-2 text-right text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            Cost
                        </th>
                        <th
                            v-if="scenarioActive"
                            class="border-b border-border px-3 py-2 text-right text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            Projected
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.key ?? 'null'"
                        class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                    >
                        <td class="font-mono-tabular px-3 py-2 text-[12px]">
                            {{ row.key ?? '—' }}
                        </td>
                        <td class="font-mono-tabular px-3 py-2 text-right">
                            {{ formatNumber(row.invocations) }}
                        </td>
                        <td class="font-mono-tabular px-3 py-2 text-right">
                            {{ formatCost(row.total_cost) }}
                        </td>
                        <td
                            v-if="scenarioActive"
                            class="font-mono-tabular px-3 py-2 text-right text-accent"
                        >
                            {{
                                formatCost(
                                    projected[row.key ?? '__null__'] ?? '0',
                                )
                            }}
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td
                            :colspan="scenarioActive ? 4 : 3"
                            class="px-3 py-6 text-center text-sm text-fg-subtle"
                        >
                            No data in this window.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
