<script setup lang="ts">
import { Link2, Unlink } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Pill, SegmentedControl } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    COMPARE_METRICS,
    canPair,
    deltaTone,
    formatDelta,
    formatMetric,
} from './compare';
import type {
    BenchmarkComparisonLine,
    BenchmarkComparisonMetric,
    BenchmarkComparisonRun,
    BenchmarkComparisonTask,
} from './types';

const props = defineProps<{
    task: BenchmarkComparisonTask;
    runs: BenchmarkComparisonRun[];
    lines: BenchmarkComparisonLine[];
}>();

const emit = defineEmits<{
    pair: [line: BenchmarkComparisonLine, other: BenchmarkComparisonLine];
    unpair: [line: BenchmarkComparisonLine];
}>();

const metric = ref<BenchmarkComparisonMetric>('pass_rate');

const metricOptions = COMPARE_METRICS.map((entry) => ({
    value: entry.value,
    label: entry.label,
}));

const rows = computed(() =>
    props.task.rows.flatMap((row) => {
        const line = props.lines.find((entry) => entry.key === row.line_key);

        return line ? [{ ...row, line }] : [];
    }),
);

function partners(line: BenchmarkComparisonLine): BenchmarkComparisonLine[] {
    if (line.members.length >= props.runs.length) {
        return [];
    }

    return rows.value
        .map((row) => row.line)
        .filter((other) => canPair(line, other));
}

const TONE_CLASS = {
    better: 'text-success',
    worse: 'text-destructive',
    same: 'text-muted-foreground',
} as const;

function deltaClass(delta: number | null): string {
    return TONE_CLASS[deltaTone(metric.value, delta)];
}
</script>

<template>
    <div class="grid min-w-0 gap-2" :data-compare-table="task.task">
        <SegmentedControl
            v-model="metric"
            :options="metricOptions"
            option-attribute="compare-metric"
        />
        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Model</TableHead>
                        <TableHead
                            v-for="(run, index) in runs"
                            :key="run.id"
                            class="text-right whitespace-nowrap"
                            >{{ run.name
                            }}<span
                                v-if="index === 0"
                                class="ml-1 text-muted-foreground"
                                >(baseline)</span
                            ></TableHead
                        >
                        <TableHead
                            ><span class="sr-only">Pairing</span></TableHead
                        >
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="row in rows"
                        :key="row.line_key"
                        :data-compare-line="row.line_key"
                    >
                        <TableCell class="whitespace-nowrap">
                            {{ row.line.label }}
                            <Pill
                                v-if="row.line.paired"
                                variant="info"
                                class="ml-1"
                                >paired</Pill
                            >
                        </TableCell>
                        <TableCell
                            v-for="(figure, index) in row.figures"
                            :key="runs[index].id"
                            class="text-right whitespace-nowrap tabular-nums"
                            :data-compare-figure="`${row.line_key}:${runs[index].id}`"
                        >
                            <template v-if="figure">
                                {{ formatMetric(metric, figure[metric]) }}
                                <Pill
                                    v-if="figure.self_judged"
                                    variant="warn"
                                    class="ml-1"
                                    data-compare-self-judged
                                    >self-judged</Pill
                                >
                                <span
                                    v-if="
                                        row.deltas[index] &&
                                        formatDelta(
                                            metric,
                                            row.deltas[index]![metric],
                                        )
                                    "
                                    :class="[
                                        'ml-1 text-[11.5px]',
                                        deltaClass(row.deltas[index]![metric]),
                                    ]"
                                    data-compare-delta
                                    >{{
                                        formatDelta(
                                            metric,
                                            row.deltas[index]![metric],
                                        )
                                    }}</span
                                >
                            </template>
                            <span v-else class="text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell class="text-right whitespace-nowrap">
                            <Button
                                v-if="row.line.paired"
                                variant="ghost"
                                size="sm"
                                class="h-7 gap-1 text-xs"
                                data-compare-unpair
                                @click="emit('unpair', row.line)"
                            >
                                <Unlink class="size-3.5" />Unpair
                            </Button>
                            <DropdownMenu
                                v-else-if="partners(row.line).length > 0"
                            >
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="h-7 gap-1 text-xs"
                                        data-compare-pair
                                    >
                                        <Link2 class="size-3.5" />Pair with…
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        v-for="other in partners(row.line)"
                                        :key="other.key"
                                        :data-compare-pair-option="other.key"
                                        @select="emit('pair', row.line, other)"
                                        >{{ other.label }}</DropdownMenuItem
                                    >
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </div>
</template>
