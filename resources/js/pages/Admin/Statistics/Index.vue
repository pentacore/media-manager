<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import StatisticsController from '@/actions/App/Http/Controllers/Admin/StatisticsController';
import {
    BarChart,
    BreakdownMeter,
    StatCard,
    TimeWindowFilter,
} from '@/components/mm';
import { useStatisticsSeries } from '@/composables/useStatisticsSeries';
import type { SeriesPoint } from '@/composables/useStatisticsSeries';
import { dashboard } from '@/routes';

interface BreakdownRow {
    key: string;
    count: number;
    sum: number;
}

interface UptimeRow {
    connection: string;
    uptime: number;
    latency: number | null;
}

const props = defineProps<{
    window: string;
    windows: { value: string; label: string }[];
    headline: {
        webhooks: number;
        actions: number;
        approvalRate: number;
        resolvedRate: number;
        agentNoActionRate: number;
    };
    webhookSeries: SeriesPoint[];
    webhooksByService: BreakdownRow[];
    actionsByStatus: BreakdownRow[];
    actionsByOrigin: BreakdownRow[];
    agentDecisions: BreakdownRow[];
    diskSeries: SeriesPoint[];
    queueSeries: SeriesPoint[];
    sessionSeries: SeriesPoint[];
    uptime: UptimeRow[];
    aiCostSeries: SeriesPoint[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Overview', href: dashboard().url },
            { title: 'Statistics', href: StatisticsController.url() },
        ],
    },
});

function setWindow(value: string): void {
    router.visit(StatisticsController.url({ query: { window: value } }), {
        preserveScroll: true,
        preserveState: true,
    });
}

const { toBarData, toAvgBarData, toSumBarData } = useStatisticsSeries();

const webhookBars = computed(() => toBarData(props.webhookSeries));
const diskBars = computed(() => toAvgBarData(props.diskSeries));
const queueBars = computed(() => toAvgBarData(props.queueSeries));
const sessionBars = computed(() => toAvgBarData(props.sessionSeries));
const aiCostBars = computed(() => toSumBarData(props.aiCostSeries));

/** Fill colour by uptime health: green ≥ 99, amber ≥ 95, red below. */
function uptimeClass(pct: number): string {
    if (pct >= 99) {
        return 'bg-success';
    }

    if (pct >= 95) {
        return 'bg-warning';
    }

    return 'bg-destructive';
}

const aiCostTotal = computed(() =>
    props.aiCostSeries.reduce((acc, point) => acc + (point.sum ?? 0), 0),
);
</script>

<template>
    <Head title="Operational statistics" />

    <div class="space-y-6 p-6">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Operational statistics</h1>
            <TimeWindowFilter
                :options="windows"
                :model-value="window"
                @update:model-value="setWindow"
            />
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
                label="Webhooks received"
                :value="headline.webhooks"
                :spark="webhookSeries.map((p) => p.count)"
            />
            <StatCard label="Actions" :value="headline.actions" />
            <StatCard
                label="Approval rate"
                :value="`${headline.approvalRate}%`"
            />
            <StatCard
                label="Resolved rate"
                :value="`${headline.resolvedRate}%`"
            />
            <StatCard
                label="Agent no-action rate"
                :value="`${headline.agentNoActionRate}%`"
            />
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="mb-4 text-sm font-semibold">Webhooks over time</h2>
                <BarChart :data="webhookBars" :height="160" />
            </div>
            <div class="rounded-xl border border-border bg-card p-5">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-sm font-semibold">AI cost (USD)</h2>
                    <span class="text-sm text-muted-foreground tabular-nums">
                        ${{ aiCostTotal.toFixed(2) }}
                    </span>
                </div>
                <BarChart :data="aiCostBars" :height="160" />
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <BreakdownMeter
                title="Webhooks by service"
                :rows="webhooksByService"
                empty-text="No webhooks in this window."
            />
            <BreakdownMeter
                title="Actions by status"
                :rows="actionsByStatus"
                empty-text="No actions in this window."
            />
            <BreakdownMeter
                title="Actions by origin"
                :rows="actionsByOrigin"
                empty-text="No actions in this window."
            />
            <BreakdownMeter
                title="Agent decisions"
                :rows="agentDecisions"
                empty-text="No agent decisions in this window."
            />
        </div>

        <div class="rounded-xl border border-border bg-card p-5">
            <h2 class="mb-4 text-sm font-semibold">Service uptime</h2>
            <table v-if="uptime.length" class="w-full text-sm">
                <thead>
                    <tr
                        class="text-left text-xs text-muted-foreground uppercase"
                    >
                        <th class="pb-2 font-medium">Connection</th>
                        <th class="pb-2 font-medium">Uptime</th>
                        <th class="pb-2 text-right font-medium">Avg latency</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in uptime"
                        :key="row.connection"
                        class="border-t border-border"
                    >
                        <td class="py-2">{{ row.connection }}</td>
                        <td class="py-2">
                            <div class="flex items-center gap-2">
                                <div
                                    class="h-1.5 w-24 overflow-hidden rounded-full bg-muted"
                                >
                                    <div
                                        class="h-full rounded-full transition-all"
                                        :class="uptimeClass(row.uptime)"
                                        :style="`width: ${Math.min(100, row.uptime)}%`"
                                    />
                                </div>
                                <span class="tabular-nums"
                                    >{{ row.uptime }}%</span
                                >
                            </div>
                        </td>
                        <td class="py-2 text-right tabular-nums">
                            {{
                                row.latency === null ? '—' : `${row.latency} ms`
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="text-sm text-muted-foreground">
                No uptime samples in this window.
            </p>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="mb-4 text-sm font-semibold">Disk free (avg)</h2>
                <BarChart :data="diskBars" :height="140" />
            </div>
            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="mb-4 text-sm font-semibold">Queue depth (avg)</h2>
                <BarChart :data="queueBars" :height="140" />
            </div>
            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="mb-4 text-sm font-semibold">
                    Active sessions (avg)
                </h2>
                <BarChart :data="sessionBars" :height="140" />
            </div>
        </div>
    </div>
</template>
