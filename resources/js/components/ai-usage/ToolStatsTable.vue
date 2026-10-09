<script setup lang="ts">
import { formatMs, formatNumber, shortClass } from './format';
import type { ToolStatRow } from './types';

defineProps<{
    rows: ToolStatRow[];
}>();

function failureRate(row: ToolStatRow): string {
    if (row.calls === 0) {
        return '0%';
    }

    return `${Math.round((row.failures / row.calls) * 100)}%`;
}
</script>

<template>
    <div
        class="overflow-hidden rounded-xl border border-border bg-card"
        data-tool-stats
    >
        <div
            class="flex items-center justify-between border-b border-border px-4 py-3"
        >
            <span
                class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
            >
                Tools
            </span>
            <span class="text-[11.5px] text-muted-foreground">
                Calls, failure rate and latency in this window.
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            class="border-b border-border px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            Tool
                        </th>
                        <th
                            v-for="h in ['Calls', 'Failures', 'p50', 'p95']"
                            :key="h"
                            class="border-b border-border px-3 py-2 text-right text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            {{ h }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.tool_class"
                        class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                        data-tool-stat-row
                    >
                        <td
                            class="font-mono-tabular px-3 py-2 text-[12px]"
                            :title="row.tool_class"
                        >
                            {{ shortClass(row.tool_class) }}
                        </td>
                        <td class="font-mono-tabular px-3 py-2 text-right">
                            {{ formatNumber(row.calls) }}
                        </td>
                        <td
                            class="font-mono-tabular px-3 py-2 text-right"
                            :class="row.failures > 0 ? 'text-destructive' : ''"
                        >
                            {{ failureRate(row) }}
                        </td>
                        <td class="font-mono-tabular px-3 py-2 text-right">
                            {{ formatMs(row.p50_ms) }}
                        </td>
                        <td class="font-mono-tabular px-3 py-2 text-right">
                            {{ formatMs(row.p95_ms) }}
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td
                            colspan="5"
                            class="px-3 py-6 text-center text-sm text-fg-subtle"
                        >
                            No tool calls in this window.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
