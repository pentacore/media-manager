<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Play, Plus, Trash2 } from '@lucide/vue';
import BenchmarkRunController from '@/actions/App/Http/Controllers/Admin/BenchmarkRunController';
import BenchmarkScheduleController from '@/actions/App/Http/Controllers/Admin/BenchmarkScheduleController';
import type { BenchmarkScheduleRow } from '@/components/benchmarks';
import { Pill, TimeStamp, Toggle } from '@/components/mm';
import { Button } from '@/components/ui/button';
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
    schedules: {
        data: BenchmarkScheduleRow[];
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
            { title: 'Benchmarks', href: BenchmarkRunController.index.url() },
            {
                title: 'Schedules',
                href: BenchmarkScheduleController.index.url(),
            },
        ],
    },
});

function outcomeVariant(
    schedule: BenchmarkScheduleRow,
): 'ok' | 'warn' | 'danger' | 'default' {
    switch (schedule.last_outcome) {
        case 'launched':
            return 'ok';
        case 'failed':
            return 'danger';
        case null:
            return 'default';
        default:
            return 'warn';
    }
}

function setEnabled(schedule: BenchmarkScheduleRow, enabled: boolean): void {
    router.patch(
        BenchmarkScheduleController.toggle.url(schedule.id),
        { enabled },
        { preserveScroll: true },
    );
}

function runNow(schedule: BenchmarkScheduleRow): void {
    router.post(
        BenchmarkScheduleController.run.url(schedule.id),
        {},
        { preserveScroll: true },
    );
}

const { confirm } = useConfirm();

async function remove(schedule: BenchmarkScheduleRow): Promise<void> {
    const confirmed = await confirm({
        title: `Delete "${schedule.name}"?`,
        description:
            'The schedule is removed. Runs it already launched are kept.',
        confirmLabel: 'Delete',
        destructive: true,
    });

    if (confirmed) {
        router.delete(BenchmarkScheduleController.destroy.url(schedule.id), {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Head title="Benchmark schedules" />

    <div class="flex flex-col gap-4 p-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-[20px] font-semibold">Benchmark schedules</h1>
                <p
                    class="mt-1 max-w-[620px] text-[12.5px] text-muted-foreground"
                >
                    Re-run a task's cases against its current tier models on a
                    schedule and get alerted when a model gets worse.
                </p>
            </div>
            <Button
                size="sm"
                class="gap-1.5"
                as-child
                data-benchmark-schedule-new
            >
                <Link :href="BenchmarkScheduleController.create.url()">
                    <Plus class="size-3.5" />New schedule
                </Link>
            </Button>
        </div>

        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Task</TableHead>
                        <TableHead>Cadence</TableHead>
                        <TableHead>Next run</TableHead>
                        <TableHead>Last outcome</TableHead>
                        <TableHead>Enabled</TableHead>
                        <TableHead class="w-[1%]" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="schedule in schedules.data"
                        :key="schedule.id"
                        :data-benchmark-schedule-row="schedule.id"
                    >
                        <TableCell class="font-medium">
                            <Link
                                :href="
                                    BenchmarkScheduleController.edit.url(
                                        schedule.id,
                                    )
                                "
                                >{{ schedule.name }}</Link
                            >
                        </TableCell>
                        <TableCell>{{ schedule.task_label }}</TableCell>
                        <TableCell>{{ schedule.cadence }}</TableCell>
                        <TableCell class="text-[12.5px] text-muted-foreground">
                            <TimeStamp
                                v-if="schedule.next_run_at"
                                :iso="schedule.next_run_at"
                            />
                            <template v-else>Paused</template>
                        </TableCell>
                        <TableCell>
                            <template v-if="schedule.last_outcome">
                                <Pill
                                    :variant="outcomeVariant(schedule)"
                                    dot
                                    data-benchmark-schedule-outcome
                                    >{{ schedule.last_outcome_label }}</Pill
                                >
                                <p
                                    v-if="schedule.last_message"
                                    class="mt-1 text-[11.5px] text-muted-foreground"
                                >
                                    {{ schedule.last_message }}
                                </p>
                                <Link
                                    v-if="schedule.latest_run"
                                    :href="
                                        BenchmarkRunController.show.url(
                                            schedule.latest_run.id,
                                        )
                                    "
                                    class="mt-1 block text-[11.5px] underline"
                                    >{{ schedule.latest_run.name }}</Link
                                >
                            </template>
                            <span
                                v-else
                                class="text-[12.5px] text-muted-foreground"
                                >Never run</span
                            >
                        </TableCell>
                        <TableCell data-benchmark-schedule-toggle>
                            <Toggle
                                :model-value="schedule.enabled"
                                @update:model-value="
                                    (enabled) => setEnabled(schedule, enabled)
                                "
                            />
                        </TableCell>
                        <TableCell class="whitespace-nowrap">
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-7 gap-1 text-xs"
                                data-benchmark-schedule-run
                                @click="runNow(schedule)"
                            >
                                <Play class="size-3.5" />Run now
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-7 gap-1 text-xs text-destructive"
                                data-benchmark-schedule-delete
                                @click="remove(schedule)"
                            >
                                <Trash2 class="size-3.5" />Delete
                            </Button>
                        </TableCell>
                    </TableRow>
                    <TableEmpty v-if="schedules.data.length === 0" :colspan="7">
                        No benchmark schedules yet.
                    </TableEmpty>
                </TableBody>
            </Table>
        </div>

        <div
            v-if="schedules.meta.last_page > 1"
            class="flex items-center justify-end gap-2"
        >
            <Button
                v-for="(link, i) in schedules.links"
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
