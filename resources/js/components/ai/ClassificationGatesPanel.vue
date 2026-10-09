<script setup lang="ts">
import { BarChart } from '@/components/mm';
import type { BarChartPoint } from '@/components/mm/BarChart.vue';

export interface GateBand {
    label: string;
    count: number;
    resolved: number;
    positive_rate: number | null;
}

export interface GateSummary {
    gate: string;
    label: string;
    threshold: number;
    total: number;
    resolved: number;
    positive: number;
    verdicts: Record<string, number>;
    audit_runs: number;
    audit_resolved: number;
    audit_positive: number;
    bands: GateBand[];
}

defineProps<{ gates: GateSummary[] }>();

function percent(value: number): string {
    return `${Math.round(value * 100)}%`;
}

function bars(gate: GateSummary): BarChartPoint[] {
    return gate.bands.map((band) => ({
        label: band.label,
        value:
            band.positive_rate === null
                ? 0
                : Math.round(band.positive_rate * 100),
        placeholder: band.resolved === 0,
        tooltip:
            band.resolved === 0
                ? `${band.label}: no resolved events yet (${band.count} event(s))`
                : `${band.label}: ${percent(band.positive_rate ?? 0)} positive, ${band.resolved} of ${band.count} event(s) resolved`,
    }));
}

function countLabel(band: GateBand): string {
    return band.resolved === 0 ? '–' : String(band.count);
}

function summaryLine(gate: GateSummary): string {
    if (gate.total === 0) {
        return 'No decisions in this window.';
    }

    const parts = Object.entries(gate.verdicts).map(
        ([verdict, count]) => `${count} ${verdict.replaceAll('_', ' ')}`,
    );

    if (gate.audit_resolved > 0) {
        parts.push(
            `${gate.audit_positive} of ${gate.audit_resolved} resolved audit run(s) needed action`,
        );
    }

    if (gate.gate === 'stuck_import' && gate.resolved > 0) {
        parts.push(`${percent(gate.positive / gate.resolved)} agreement`);
    }

    return parts.join(' · ');
}
</script>

<template>
    <div
        class="overflow-hidden rounded-xl border border-border bg-card"
        data-classification-gates
    >
        <div
            class="flex items-center justify-between border-b border-border px-4 py-3"
        >
            <span
                class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
            >
                Classification gates
            </span>
            <span class="text-[11.5px] text-muted-foreground">
                How often each probability band turned out right. The dashed
                line is the current threshold.
            </span>
        </div>
        <div class="grid gap-4 p-4 md:grid-cols-2">
            <div
                v-for="gate in gates"
                :key="gate.gate"
                class="flex flex-col gap-2"
                :data-gate="gate.gate"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <span class="text-[13px] font-medium">{{
                        gate.label
                    }}</span>
                    <span class="text-[11.5px] text-muted-foreground">
                        Threshold {{ percent(gate.threshold) }}
                    </span>
                </div>
                <BarChart
                    :data="bars(gate)"
                    :height="90"
                    :marker-at="gate.threshold"
                    :max="100"
                />
                <div
                    class="flex justify-between text-[10px] text-muted-foreground tabular-nums"
                    data-gate-counts
                >
                    <span
                        v-for="band in gate.bands"
                        :key="`count-${gate.gate}-${band.label}`"
                        class="flex-1 text-center"
                    >
                        {{ countLabel(band) }}
                    </span>
                </div>
                <p
                    class="text-[11.5px] text-muted-foreground"
                    data-gate-summary
                >
                    {{ summaryLine(gate) }}
                </p>
            </div>
        </div>
    </div>
</template>
