<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import BenchmarkRunController from '@/actions/App/Http/Controllers/Admin/BenchmarkRunController';
import {
    CompareRunBar,
    CompareTaskTable,
    compareState,
    compareUrl,
    pairKey,
    withoutLinePairs,
} from '@/components/benchmarks';
import type {
    BenchmarkCompareOutput,
    BenchmarkComparison,
    BenchmarkComparisonLine,
    BenchmarkJudgeOptions,
    BenchmarkNeutralRejudgeSummary,
    BenchmarkPickableRun,
    BenchmarkResultDetail,
    CompareState,
} from '@/components/benchmarks';
import { dashboard } from '@/routes';

const props = defineProps<{
    comparison: BenchmarkComparison;
    rejudge: BenchmarkNeutralRejudgeSummary | null;
    pickableRuns: BenchmarkPickableRun[];
    judgeOptions: BenchmarkJudgeOptions;
    aiEnabled: boolean;
    resultDetail?: BenchmarkResultDetail;
    outputs?: BenchmarkCompareOutput[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'Benchmarks', href: BenchmarkRunController.index.url() },
        ],
    },
});

const state = computed(() => compareState(props.comparison));

function visit(next: Partial<CompareState>): void {
    router.get(
        compareUrl({ ...state.value, ...next }),
        {},
        { preserveScroll: true },
    );
}

function changeRuns(runs: number[], baseline: number): void {
    visit({ runs, baseline });
}

function pair(
    line: BenchmarkComparisonLine,
    other: BenchmarkComparisonLine,
): void {
    visit({ pairs: [...state.value.pairs, pairKey(line, other)] });
}

function unpair(line: BenchmarkComparisonLine): void {
    visit({ pairs: withoutLinePairs(state.value.pairs, line) });
}
</script>

<template>
    <Head title="Compare runs" />

    <div class="flex min-w-0 flex-col gap-6 p-5" data-compare-page>
        <div>
            <h1 class="text-[20px] font-semibold">Compare runs</h1>
            <p class="mt-1 text-[12.5px] text-muted-foreground">
                Figures count only the cases every run has; deltas are against
                the baseline.
            </p>
        </div>

        <CompareRunBar
            :runs="comparison.runs"
            :pickable-runs="pickableRuns"
            @change="changeRuns"
        />

        <!-- Task 10: NeutralJudgePanel goes here -->

        <p
            v-if="comparison.tasks.length === 0"
            class="text-[13px] text-muted-foreground"
        >
            These runs have no results to compare.
        </p>

        <section
            v-for="task in comparison.tasks"
            :key="task.task"
            class="grid min-w-0 gap-3"
            :data-compare-task="task.task"
        >
            <header class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 class="text-[15px] font-semibold">{{ task.task_label }}</h2>
                <p
                    class="text-[12.5px] text-muted-foreground"
                    data-compare-task-counts
                >
                    {{ task.common_cases }} cases common to all runs ·
                    {{ task.excluded_cases }} excluded
                </p>
            </header>
            <p
                v-if="task.rows.length === 0"
                class="text-[13px] text-muted-foreground"
                data-compare-task-empty
            >
                These runs share no cases for this task
            </p>
            <template v-else>
                <CompareTaskTable
                    :task="task"
                    :runs="comparison.runs"
                    :lines="comparison.lines"
                    @pair="pair"
                    @unpair="unpair"
                />
                <!-- Task 9: CompareCaseGrid goes here -->
            </template>
        </section>

        <!-- Task 9: ResultDrawer and CompareOutputsDialog go here -->
    </div>
</template>
