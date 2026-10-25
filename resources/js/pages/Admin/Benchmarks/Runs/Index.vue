<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Ban, GitCompareArrows, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import BenchmarkCaseController from '@/actions/App/Http/Controllers/Admin/BenchmarkCaseController';
import BenchmarkRunController from '@/actions/App/Http/Controllers/Admin/BenchmarkRunController';
import BenchmarkScheduleController from '@/actions/App/Http/Controllers/Admin/BenchmarkScheduleController';
import { compareUrl, formatUsd } from '@/components/benchmarks';
import type { BenchmarkRunRow } from '@/components/benchmarks';
import { PageHeader, Pill, TimeStamp } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useConfirm } from '@/composables/useConfirm';
import { dashboard } from '@/routes';

defineProps<{
    runs: {
        data: BenchmarkRunRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            {
                title: 'Benchmarks',
                href: BenchmarkRunController.index.url(),
            },
        ],
    },
});

function isActive(run: BenchmarkRunRow): boolean {
    return run.status === 'pending' || run.status === 'running';
}

function statusVariant(
    run: BenchmarkRunRow,
): 'ok' | 'warn' | 'danger' | 'info' | 'default' {
    switch (run.status) {
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
}

const MIN_COMPARED = 2;
const MAX_COMPARED = 5;

const selected = ref<number[]>([]);

function toggle(run: BenchmarkRunRow, checked: boolean): void {
    selected.value = checked
        ? [...selected.value, run.id]
        : selected.value.filter((id) => id !== run.id);
}

const canCompare = computed(
    () =>
        selected.value.length >= MIN_COMPARED &&
        selected.value.length <= MAX_COMPARED,
);

function compareSelected(): void {
    const runs = [...selected.value].sort((a, b) => a - b);

    router.visit(
        compareUrl({ runs, baseline: runs[0], pairs: [], scores: 'original' }),
    );
}

const { confirm } = useConfirm();

async function cancel(run: BenchmarkRunRow): Promise<void> {
    const confirmed = await confirm({
        title: `Cancel "${run.name}"?`,
        description: 'Queued results are skipped; finished ones are kept.',
        confirmLabel: 'Cancel run',
        destructive: true,
    });

    if (confirmed) {
        router.post(
            BenchmarkRunController.cancel.url(run.id),
            {},
            { preserveScroll: true },
        );
    }
}

async function remove(run: BenchmarkRunRow): Promise<void> {
    const confirmed = await confirm({
        title: `Delete "${run.name}"?`,
        description: 'The run and all of its results are removed.',
        confirmLabel: 'Delete',
        destructive: true,
    });

    if (confirmed) {
        router.delete(BenchmarkRunController.destroy.url(run.id), {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Head title="Benchmarks" />

    <div class="flex flex-col gap-4 p-5">
        <PageHeader align="start" class="sm:gap-4">
            <template #title>
                <h1 class="text-[20px] font-semibold">Benchmarks</h1>
            </template>
            <template #description>
                <p
                    class="mt-1 max-w-[620px] text-[12.5px] text-muted-foreground"
                >
                    Replay fixed cases against candidate models and compare
                    quality, cost and latency.
                </p>
            </template>
            <template #secondaryActions>
                <Button
                    variant="outline"
                    size="sm"
                    as-child
                    data-benchmark-cases-link
                >
                    <Link :href="BenchmarkCaseController.index.url()"
                        >Cases</Link
                    >
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    as-child
                    data-benchmark-schedules-link
                >
                    <Link :href="BenchmarkScheduleController.index.url()"
                        >Schedules</Link
                    >
                </Button>
            </template>
            <template #primaryAction>
                <Button
                    size="sm"
                    class="gap-1.5"
                    as-child
                    data-benchmark-run-new
                >
                    <Link :href="BenchmarkRunController.create.url()">
                        <Plus class="size-3.5" />New run
                    </Link>
                </Button>
            </template>
            <template #menu>
                <DropdownMenuItem as-child data-page-header-item="cases">
                    <Link :href="BenchmarkCaseController.index.url()"
                        >Cases</Link
                    >
                </DropdownMenuItem>
                <DropdownMenuItem as-child data-page-header-item="schedules">
                    <Link :href="BenchmarkScheduleController.index.url()"
                        >Schedules</Link
                    >
                </DropdownMenuItem>
            </template>
        </PageHeader>

        <div
            v-if="selected.length > 0"
            class="flex flex-wrap items-center gap-3 text-[12.5px]"
            data-benchmark-compare-bar
        >
            <span>{{ selected.length }} selected</span>
            <Button
                size="sm"
                class="gap-1.5"
                :disabled="!canCompare"
                data-benchmark-compare-selected
                @click="compareSelected"
            >
                <GitCompareArrows class="size-3.5" />Compare
            </Button>
            <span v-if="!canCompare" class="text-muted-foreground"
                >Pick 2 to 5 finished runs.</span
            >
        </div>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead class="w-[1%]"
                            ><span class="sr-only">Compare</span></TableHead
                        >
                        <TableHead>Name</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Cases × candidates</TableHead>
                        <TableHead class="text-right"
                            >Cost / estimate</TableHead
                        >
                        <TableHead>Created</TableHead>
                        <TableHead class="w-[1%]" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="run in runs.data"
                        :key="run.id"
                        class="cursor-pointer"
                        :data-benchmark-run-row="run.id"
                        @click="
                            router.visit(
                                BenchmarkRunController.show.url(run.id),
                            )
                        "
                    >
                        <TableCell class="w-[1%]" @click.stop>
                            <Checkbox
                                :model-value="selected.includes(run.id)"
                                :disabled="!run.comparable"
                                :aria-label="`Compare ${run.name}`"
                                :title="
                                    run.comparable
                                        ? undefined
                                        : 'Only finished runs can be compared'
                                "
                                :data-benchmark-run-select="run.id"
                                @update:model-value="
                                    (value) => toggle(run, value === true)
                                "
                            />
                        </TableCell>
                        <TableCell class="font-medium">
                            <Link
                                :href="BenchmarkRunController.show.url(run.id)"
                                @click.stop
                                >{{ run.name }}</Link
                            >
                            <Link
                                v-if="run.schedule"
                                :href="
                                    BenchmarkScheduleController.edit.url(
                                        run.schedule.id,
                                    )
                                "
                                class="ml-2 align-middle"
                                :title="run.schedule.name"
                                data-benchmark-run-scheduled
                                @click.stop
                            >
                                <Pill variant="info">Scheduled</Pill>
                            </Link>
                        </TableCell>
                        <TableCell>
                            <Pill :variant="statusVariant(run)" dot>{{
                                run.status_label
                            }}</Pill>
                        </TableCell>
                        <TableCell class="tabular-nums"
                            >{{ run.case_count }} ×
                            {{ run.candidate_count }}</TableCell
                        >
                        <TableCell class="text-right tabular-nums"
                            >{{ formatUsd(run.cost_usd) }} /
                            {{ formatUsd(run.estimate_usd) }}</TableCell
                        >
                        <TableCell class="text-[12.5px] text-muted-foreground">
                            <TimeStamp :iso="run.created_at" />
                            <template v-if="run.created_by">
                                · {{ run.created_by }}</template
                            >
                        </TableCell>
                        <TableCell class="whitespace-nowrap" @click.stop>
                            <Button
                                v-if="isActive(run)"
                                variant="ghost"
                                size="sm"
                                class="h-7 gap-1 text-xs"
                                data-benchmark-run-cancel
                                @click="cancel(run)"
                            >
                                <Ban class="size-3.5" />Cancel
                            </Button>
                            <Button
                                v-else
                                variant="ghost"
                                size="sm"
                                class="h-7 gap-1 text-xs text-destructive"
                                data-benchmark-run-delete
                                @click="remove(run)"
                            >
                                <Trash2 class="size-3.5" />Delete
                            </Button>
                        </TableCell>
                    </TableRow>
                    <TableEmpty v-if="runs.data.length === 0" :colspan="7">
                        No benchmark runs yet.
                    </TableEmpty>
                </TableBody>
            </Table>
        </div>

        <div
            v-if="runs.meta.last_page > 1"
            class="flex items-center justify-end gap-2"
        >
            <Button
                v-for="(link, i) in runs.links"
                :key="i"
                as="a"
                :href="link.url ?? '#'"
                size="sm"
                :variant="link.active ? 'default' : 'outline'"
                :disabled="!link.url"
                class="h-7 min-w-7 px-2 text-xs"
            >
                <span v-html="link.label" />
            </Button>
        </div>
    </div>
</template>
