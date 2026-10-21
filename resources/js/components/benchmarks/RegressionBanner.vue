<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import BenchmarkRunController from '@/actions/App/Http/Controllers/Admin/BenchmarkRunController';
import { formatPercent } from './format';
import type { BenchmarkRegression } from './types';

defineProps<{
    regressions: BenchmarkRegression[];
    baseline: { id: number; name: string } | null;
}>();

const METRIC_LABELS: Record<
    BenchmarkRegression['metrics'][number]['metric'],
    string
> = {
    pass_rate: 'pass rate',
    avg_score: 'score',
    error_rate: 'errors',
};

const REASONING_LABELS: Record<string, string> = {
    none: 'None',
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    xhigh: 'Extra high',
    max: 'Max',
};

function candidateName(regression: BenchmarkRegression): string {
    const { provider, model, reasoning } = regression.candidate;
    const label =
        reasoning && reasoning !== 'provider_default'
            ? (REASONING_LABELS[reasoning] ?? reasoning)
            : null;

    return `${provider}/${model}${label ? ` (${label})` : ''}`;
}

function metricsText(regression: BenchmarkRegression): string {
    return regression.metrics
        .map(
            (metric) =>
                `${METRIC_LABELS[metric.metric]} ${formatPercent(metric.before)} → ${formatPercent(metric.after)}`,
        )
        .join(', ');
}
</script>

<template>
    <div
        v-if="regressions.length > 0"
        class="grid gap-2 rounded-xl border border-warning/40 bg-warning/10 p-4 text-[13px]"
        data-benchmark-regressions
    >
        <div class="flex items-center gap-2 font-semibold">
            <TriangleAlert class="size-4 text-warning" />
            <span>
                Compared with
                <Link
                    v-if="baseline"
                    :href="BenchmarkRunController.show.url(baseline.id)"
                    class="underline"
                    >{{ baseline.name }}</Link
                >
                <template v-else>an earlier run</template>
            </span>
        </div>
        <ul class="grid gap-1">
            <li
                v-for="(regression, i) in regressions"
                :key="i"
                :data-benchmark-regression="i"
            >
                {{ candidateName(regression) }}: {{ metricsText(regression) }}
                <span class="text-muted-foreground"
                    >· {{ regression.paired_cases }} paired cases</span
                >
            </li>
        </ul>
    </div>
    <p
        v-else
        class="text-[12.5px] text-muted-foreground"
        data-benchmark-no-regression
    >
        <template v-if="baseline">
            No regression against
            <Link
                :href="BenchmarkRunController.show.url(baseline.id)"
                class="underline"
                >{{ baseline.name }}</Link
            >
        </template>
        <template v-else>No earlier comparable run</template>
    </p>
</template>
