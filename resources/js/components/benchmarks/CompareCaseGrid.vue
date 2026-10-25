<script setup lang="ts">
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { compareCellClass, compareCellText } from './compare';
import type {
    BenchmarkComparisonCaseRow,
    BenchmarkComparisonLine,
    BenchmarkComparisonRun,
    BenchmarkComparisonTask,
} from './types';

const props = defineProps<{
    task: BenchmarkComparisonTask;
    runs: BenchmarkComparisonRun[];
    lines: BenchmarkComparisonLine[];
}>();

const emit = defineEmits<{
    openResult: [resultId: number, runId: number];
    compareOutputs: [baselineResultId: number, otherResultId: number];
}>();

const showAll = ref(false);

const changedCount = computed(
    () => props.task.cases.filter((row) => row.changed).length,
);

const visibleRows = computed(() =>
    showAll.value
        ? props.task.cases
        : props.task.cases.filter((row) => row.changed),
);

function lineLabel(row: BenchmarkComparisonCaseRow): string {
    return props.lines.find((line) => line.key === row.line_key)?.label ?? '';
}
</script>

<template>
    <div class="grid min-w-0 gap-2" :data-compare-case-grid="task.task">
        <div class="flex flex-wrap items-center gap-3">
            <p
                class="text-[12.5px] text-muted-foreground"
                data-compare-changed-count
            >
                {{ changedCount }} of {{ task.cases.length }} rows changed vs
                the baseline
            </p>
            <Button
                variant="outline"
                size="sm"
                class="h-7 text-xs"
                data-compare-show-all
                @click="showAll = !showAll"
                >{{ showAll ? 'Only changed' : 'Show all cases' }}</Button
            >
        </div>
        <p
            v-if="visibleRows.length === 0"
            class="text-[13px] text-muted-foreground"
            data-compare-no-changes
        >
            No case changed against the baseline.
        </p>
        <div
            v-else
            class="overflow-x-auto rounded-xl border border-border bg-card"
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Case</TableHead>
                        <TableHead>Model</TableHead>
                        <TableHead
                            v-for="run in runs"
                            :key="run.id"
                            class="text-center whitespace-nowrap"
                            >{{ run.name }}</TableHead
                        >
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="row in visibleRows"
                        :key="`${row.case_id}:${row.line_key}`"
                        :data-compare-case-row="`${row.case_id}:${row.line_key}`"
                    >
                        <TableCell class="font-medium">{{
                            row.case_name
                        }}</TableCell>
                        <TableCell
                            class="text-[12.5px] whitespace-nowrap text-muted-foreground"
                            >{{ lineLabel(row) }}</TableCell
                        >
                        <TableCell
                            v-for="(cell, index) in row.cells"
                            :key="runs[index].id"
                            class="text-center whitespace-nowrap"
                        >
                            <span
                                v-if="cell"
                                class="inline-flex items-center gap-1 touch:gap-2"
                            >
                                <button
                                    type="button"
                                    :class="[
                                        'min-w-10 rounded-md px-2 py-1 text-[12.5px] tabular-nums hover:bg-bg-hover touch:h-10',
                                        compareCellClass(cell),
                                    ]"
                                    :data-compare-cell="cell.result_id"
                                    @click="
                                        emit(
                                            'openResult',
                                            cell.result_id,
                                            runs[index].id,
                                        )
                                    "
                                >
                                    {{ compareCellText(cell) }}
                                </button>
                                <Button
                                    v-if="index > 0 && row.cells[0]"
                                    variant="ghost"
                                    size="sm"
                                    class="h-6 px-1.5 text-[11px] touch:h-10 touch:min-w-10"
                                    :data-compare-vs-baseline="`${row.cells[0]!.result_id}:${cell.result_id}`"
                                    @click="
                                        emit(
                                            'compareOutputs',
                                            row.cells[0]!.result_id,
                                            cell.result_id,
                                        )
                                    "
                                    >vs baseline</Button
                                >
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </div>
</template>
