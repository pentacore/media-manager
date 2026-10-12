<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { formatUsd } from './format';
import type { BenchmarkEstimate, BenchmarkSelectionForm } from './types';

const props = defineProps<{
    estimate: BenchmarkEstimate;
    candidates: BenchmarkSelectionForm[];
    costCap: number | null;
}>();

const perCandidate = computed(() =>
    props.candidates.map((candidate, index) => ({
        candidate,
        usd: props.estimate.rows
            .filter((row) => row.candidate_index === index)
            .reduce((sum, row) => sum + row.usd, 0),
    })),
);
</script>

<template>
    <section
        class="grid gap-2 rounded-lg border border-border bg-card p-4"
        data-benchmark-estimate
    >
        <p class="text-[13px]">
            Estimated total
            <strong class="tabular-nums" data-benchmark-estimate-total>{{
                formatUsd(estimate.total_usd)
            }}</strong>
            <span class="text-muted-foreground">
                (judge {{ formatUsd(estimate.judge_usd) }})</span
            >
        </p>
        <ul class="grid gap-0.5 text-[12.5px] text-muted-foreground">
            <li
                v-for="(row, index) in perCandidate"
                :key="index"
                class="tabular-nums"
            >
                {{ row.candidate.model }}: {{ formatUsd(row.usd) }}
            </li>
        </ul>
        <p
            v-if="estimate.unpriced.length > 0"
            class="flex items-start gap-1.5 text-[12.5px] text-warning"
            data-benchmark-estimate-unpriced
        >
            <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
            No price for {{ estimate.unpriced.join(', ') }} — counted as $0.
        </p>
        <p
            v-if="costCap !== null && estimate.total_usd > costCap"
            class="text-[12.5px] text-warning"
        >
            The estimate is above the cost cap; the run may stop early.
        </p>
    </section>
</template>
