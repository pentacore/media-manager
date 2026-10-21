<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import EstimateSummary from './EstimateSummary.vue';
import type {
    BenchmarkEstimate,
    BenchmarkSelection,
    BenchmarkSelectionForm,
} from './types';

const props = defineProps<{
    candidates: BenchmarkSelection[];
    estimate: BenchmarkEstimate | null;
    costCap: number | null;
    estimating: boolean;
    canEstimate: boolean;
}>();

defineEmits<{
    (event: 'estimate'): void;
}>();

function reasoningText(reasoning: string | null): string {
    return (reasoning ?? 'provider_default').replaceAll('_', ' ');
}

const summaryCandidates = computed(
    () => props.candidates as BenchmarkSelectionForm[],
);

const overCap = computed(
    () =>
        props.estimate !== null &&
        props.costCap !== null &&
        props.estimate.total_usd > props.costCap,
);
</script>

<template>
    <section class="grid gap-2" data-benchmark-schedule-preview>
        <h2 class="text-[13px] font-semibold">Models it will test</h2>
        <ul
            v-if="candidates.length > 0"
            class="grid gap-0.5 text-[12.5px]"
            data-benchmark-schedule-candidates
        >
            <li
                v-for="(candidate, index) in candidates"
                :key="index"
                class="tabular-nums"
            >
                {{ candidate.provider }}/{{ candidate.model }}
                <span class="text-muted-foreground"
                    >({{ reasoningText(candidate.reasoning) }})</span
                >
            </li>
        </ul>
        <p
            v-else
            class="text-[12.5px] text-muted-foreground"
            data-benchmark-schedule-candidates
        >
            This task has no tier models.
        </p>

        <div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="!canEstimate || estimating"
                data-benchmark-schedule-estimate-button
                @click="$emit('estimate')"
            >
                {{ estimating ? 'Estimating…' : 'Estimate' }}
            </Button>
        </div>

        <EstimateSummary
            v-if="estimate"
            :estimate="estimate"
            :candidates="summaryCandidates"
            :cost-cap="null"
        />
        <div
            v-if="overCap"
            class="rounded-md border border-warning/30 bg-warning/10 p-3 text-[12.5px] text-warning"
            data-benchmark-schedule-over-cap
        >
            This would be skipped today: the estimate is over the cost cap.
        </div>
    </section>
</template>
