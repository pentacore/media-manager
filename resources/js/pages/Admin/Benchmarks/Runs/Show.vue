<script setup lang="ts">
import { Head, router, usePoll } from '@inertiajs/vue3';
import { Ban, RefreshCw, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BenchmarkRunController from '@/actions/App/Http/Controllers/Admin/BenchmarkRunController';
import {
    ResultDrawer,
    candidateLabel,
    formatLatency,
    formatPercent,
    formatScore,
    formatUsd,
    isRejudging,
} from '@/components/benchmarks';
import type {
    BenchmarkMatrixCell,
    BenchmarkMatrixTask,
    BenchmarkResultDetail,
    BenchmarkRunDetail,
} from '@/components/benchmarks';
import { Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useConfirm } from '@/composables/useConfirm';
import { dashboard } from '@/routes';

const props = defineProps<{
    run: BenchmarkRunDetail;
    matrix: BenchmarkMatrixTask[];
    resultDetail?: BenchmarkResultDetail;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'Benchmarks', href: BenchmarkRunController.index.url() },
        ],
    },
});

const POLL_INTERVAL_MS = 5_000;

const isActive = computed(
    () => props.run.status === 'pending' || props.run.status === 'running',
);

const { start: startPolling, stop: stopPolling } = usePoll(
    POLL_INTERVAL_MS,
    { only: ['run', 'matrix'] },
    { autoStart: false },
);

const allCells = computed(() =>
    props.matrix.flatMap((task) => task.cases.flatMap((row) => row.cells)),
);

const hasFailedJudge = computed(() =>
    allCells.value.some((cell) => cell.judge_status === 'failed'),
);

const needsPolling = computed(
    () =>
        isActive.value ||
        allCells.value.some((cell) => isRejudging(cell.judge_status)),
);

watch(
    needsPolling,
    (active) => {
        if (active) {
            startPolling();
        } else {
            stopPolling();
        }
    },
    { immediate: true },
);

const done = computed(
    () =>
        props.run.counts.ok +
        props.run.counts.failed +
        props.run.counts.skipped,
);

const statusVariant = computed<'ok' | 'warn' | 'danger' | 'info' | 'default'>(
    () => {
        switch (props.run.status) {
            case 'completed':
                return 'ok';
            case 'running':
            case 'pending':
                return 'info';
            case 'stopped_cost_cap':
                return 'warn';
            case 'failed':
                return 'danger';
            default:
                return 'default';
        }
    },
);

const { confirm } = useConfirm();

async function cancel(): Promise<void> {
    const confirmed = await confirm({
        title: `Cancel "${props.run.name}"?`,
        description: 'Queued results are skipped; finished ones are kept.',
        confirmLabel: 'Cancel run',
        destructive: true,
    });

    if (confirmed) {
        router.post(
            BenchmarkRunController.cancel.url(props.run.id),
            {},
            { preserveScroll: true },
        );
    }
}

function rejudge(): void {
    router.post(
        BenchmarkRunController.rejudge.url(props.run.id),
        {},
        { preserveScroll: true },
    );
}

async function remove(): Promise<void> {
    const confirmed = await confirm({
        title: `Delete "${props.run.name}"?`,
        description: 'The run and all of its results are removed.',
        confirmLabel: 'Delete',
        destructive: true,
    });

    if (confirmed) {
        router.delete(BenchmarkRunController.destroy.url(props.run.id));
    }
}

type Metric = keyof BenchmarkMatrixTask['best'];

function best(
    task: BenchmarkMatrixTask,
    metric: Metric,
    index: number,
): string {
    return task.best[metric] === index ? 'font-semibold text-success' : '';
}

const drawerOpen = ref(false);
const drawerLoading = ref(false);
const selectedResultId = ref<number | null>(null);

const drawerDetail = computed(() =>
    props.resultDetail?.results.some(
        (result) => result.result_id === selectedResultId.value,
    )
        ? props.resultDetail
        : null,
);

function openResult(cell: BenchmarkMatrixCell): void {
    selectedResultId.value = cell.result_id;
    drawerOpen.value = true;
    drawerLoading.value = true;

    router.reload({
        only: ['resultDetail'],
        data: { result: cell.result_id },
        onFinish: () => {
            drawerLoading.value = false;
        },
    });
}

function cellFor(
    cells: BenchmarkMatrixCell[],
    index: number,
): BenchmarkMatrixCell | undefined {
    return cells.find((cell) => cell.candidate_index === index);
}

function cellText(cell: BenchmarkMatrixCell): string {
    if (cell.status === 'ok') {
        if (isRejudging(cell.judge_status)) {
            return 're-judging…';
        }

        if (cell.judge_status === 'failed') {
            return 'judge failed';
        }

        if (cell.score !== null) {
            return formatScore(cell.score);
        }

        return cell.passed === null ? 'unscored' : cell.passed ? '✓' : '✗';
    }

    return cell.status;
}

function cellClass(cell: BenchmarkMatrixCell): string {
    if (cell.status === 'failed') {
        return 'text-destructive';
    }

    if (cell.status !== 'ok') {
        return 'text-muted-foreground';
    }

    if (cell.passed === null) {
        return cell.judge_status === 'failed'
            ? 'text-destructive'
            : 'text-warning';
    }

    return cell.passed ? 'text-success' : 'text-destructive';
}
</script>

<template>
    <Head :title="run.name" />

    <div class="flex flex-col gap-6 p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="grid gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-[20px] font-semibold">{{ run.name }}</h1>
                    <Pill :variant="statusVariant" dot data-benchmark-status>{{
                        run.status_label
                    }}</Pill>
                </div>
                <div
                    class="flex flex-wrap gap-x-5 gap-y-1 text-[12.5px] text-muted-foreground tabular-nums"
                    data-benchmark-progress
                >
                    <span
                        >Progress
                        <strong class="text-foreground"
                            >{{ done }} / {{ run.counts.total }}</strong
                        ></span
                    >
                    <span
                        v-if="run.counts.running > 0"
                        data-benchmark-running-count
                        >{{ run.counts.running }} running</span
                    >
                    <span v-if="run.counts.failed > 0"
                        >{{ run.counts.failed }} failed</span
                    >
                    <span v-if="run.counts.skipped > 0"
                        >{{ run.counts.skipped }} skipped</span
                    >
                    <span
                        >Cost
                        <strong class="text-foreground">{{
                            formatUsd(run.cost_usd)
                        }}</strong>
                        of {{ formatUsd(run.estimate_usd) }} estimated</span
                    >
                    <span v-if="run.cost_cap_usd !== null"
                        >Cap {{ formatUsd(run.cost_cap_usd) }}</span
                    >
                </div>
            </div>
            <div class="flex gap-2">
                <Button
                    v-if="isActive"
                    variant="outline"
                    size="sm"
                    class="gap-1.5"
                    data-benchmark-cancel
                    @click="cancel"
                >
                    <Ban class="size-3.5" />Cancel
                </Button>
                <Button
                    v-if="hasFailedJudge"
                    variant="outline"
                    size="sm"
                    class="gap-1.5"
                    data-benchmark-rejudge
                    @click="rejudge"
                >
                    <RefreshCw class="size-3.5" />Re-judge unscored
                </Button>
                <Button
                    v-if="!isActive"
                    variant="outline"
                    size="sm"
                    class="gap-1.5 text-destructive"
                    data-benchmark-delete
                    @click="remove"
                >
                    <Trash2 class="size-3.5" />Delete
                </Button>
            </div>
        </div>

        <p v-if="matrix.length === 0" class="text-[13px] text-muted-foreground">
            No results yet.
        </p>

        <section
            v-for="task in matrix"
            :key="task.task"
            class="grid gap-3"
            :data-benchmark-task-section="task.task"
        >
            <h2 class="text-[15px] font-semibold">{{ task.task_label }}</h2>

            <div
                class="overflow-x-auto rounded-xl border border-border bg-card"
                data-benchmark-matrix
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Candidate</TableHead>
                            <TableHead class="text-right">Avg score</TableHead>
                            <TableHead class="text-right">Pass rate</TableHead>
                            <TableHead class="text-right"
                                >Cost / case</TableHead
                            >
                            <TableHead class="text-right">p50</TableHead>
                            <TableHead class="text-right">p95</TableHead>
                            <TableHead class="text-right">Misses</TableHead>
                            <TableHead class="text-right">Failures</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="candidate in task.candidates"
                            :key="candidate.index"
                            :data-benchmark-matrix-row="candidate.index"
                        >
                            <TableCell class="whitespace-nowrap">
                                {{ candidateLabel(candidate) }}
                                <Pill
                                    v-if="candidate.self_judged"
                                    variant="warn"
                                    class="ml-1"
                                    data-benchmark-self-judged
                                    >self-judged</Pill
                                >
                            </TableCell>
                            <TableCell
                                :class="[
                                    'text-right tabular-nums',
                                    best(task, 'avg_score', candidate.index),
                                ]"
                                >{{
                                    formatScore(candidate.avg_score)
                                }}</TableCell
                            >
                            <TableCell
                                :class="[
                                    'text-right tabular-nums',
                                    best(task, 'pass_rate', candidate.index),
                                ]"
                                >{{
                                    formatPercent(candidate.pass_rate)
                                }}</TableCell
                            >
                            <TableCell
                                :class="[
                                    'text-right tabular-nums',
                                    best(
                                        task,
                                        'cost_per_case',
                                        candidate.index,
                                    ),
                                ]"
                                >{{
                                    formatUsd(candidate.cost_per_case)
                                }}</TableCell
                            >
                            <TableCell
                                :class="[
                                    'text-right tabular-nums',
                                    best(task, 'latency_p50', candidate.index),
                                ]"
                                >{{
                                    formatLatency(candidate.latency_p50)
                                }}</TableCell
                            >
                            <TableCell class="text-right tabular-nums">{{
                                formatLatency(candidate.latency_p95)
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                candidate.tool_misses
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                candidate.failed
                            }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <div
                class="overflow-x-auto rounded-xl border border-border bg-card"
                data-benchmark-case-grid
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Case</TableHead>
                            <TableHead
                                v-for="candidate in task.candidates"
                                :key="candidate.index"
                                class="text-center whitespace-nowrap"
                                >{{ candidateLabel(candidate) }}</TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="caseRow in task.cases"
                            :key="caseRow.case_id ?? caseRow.case_name"
                        >
                            <TableCell class="font-medium">{{
                                caseRow.case_name
                            }}</TableCell>
                            <TableCell
                                v-for="candidate in task.candidates"
                                :key="candidate.index"
                                class="text-center"
                            >
                                <button
                                    v-if="
                                        cellFor(caseRow.cells, candidate.index)
                                    "
                                    type="button"
                                    :class="[
                                        'min-w-10 rounded-md px-2 py-1 text-[12.5px] tabular-nums hover:bg-bg-hover',
                                        cellClass(
                                            cellFor(
                                                caseRow.cells,
                                                candidate.index,
                                            )!,
                                        ),
                                    ]"
                                    :data-benchmark-cell="
                                        cellFor(caseRow.cells, candidate.index)!
                                            .result_id
                                    "
                                    @click="
                                        openResult(
                                            cellFor(
                                                caseRow.cells,
                                                candidate.index,
                                            )!,
                                        )
                                    "
                                >
                                    {{
                                        cellText(
                                            cellFor(
                                                caseRow.cells,
                                                candidate.index,
                                            )!,
                                        )
                                    }}
                                </button>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </section>

        <ResultDrawer
            v-model:open="drawerOpen"
            :detail="drawerDetail"
            :candidates="run.candidates"
            :loading="drawerLoading"
        />
    </div>
</template>
