<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import BenchmarkCaseController from '@/actions/App/Http/Controllers/Admin/BenchmarkCaseController';
import type {
    BenchmarkCaseRow,
    BenchmarkTaskOption,
} from '@/components/benchmarks';
import { Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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

const props = defineProps<{
    cases: {
        data: BenchmarkCaseRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    };
    tasks: BenchmarkTaskOption[];
    filters: { task: string | null; tag: string | null };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            {
                title: 'Benchmark cases',
                href: BenchmarkCaseController.index.url(),
            },
        ],
    },
});

const taskFilter = ref(props.filters.task ?? '');
const tagFilter = ref(props.filters.tag ?? '');

function applyFilters(): void {
    const query: Record<string, string> = {};

    if (taskFilter.value !== '') {
        query.task = taskFilter.value;
    }

    if (tagFilter.value.trim() !== '') {
        query.tag = tagFilter.value.trim();
    }

    router.get(BenchmarkCaseController.index.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
    });
}

const SCORING_LABELS: Record<string, string> = {
    actions: 'Actions',
    prices: 'Prices',
    judge: 'Judge',
};

const { confirm } = useConfirm();

async function remove(benchmarkCase: BenchmarkCaseRow): Promise<void> {
    const confirmed = await confirm({
        title: `Delete "${benchmarkCase.name}"?`,
        description:
            'Past benchmark results keep their scores but lose the link to this case.',
        confirmLabel: 'Delete',
        destructive: true,
    });

    if (!confirmed) {
        return;
    }

    router.delete(BenchmarkCaseController.destroy.url(benchmarkCase.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Benchmark cases" />

    <div class="flex flex-col gap-4 p-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-[20px] font-semibold">Benchmark cases</h1>
                <p
                    class="mt-1 max-w-[620px] text-[12.5px] text-muted-foreground"
                >
                    Fixed prompts with recorded tool results. Benchmark runs
                    replay them against candidate models and score the answers.
                    Build one from scratch, or from an AI usage record.
                </p>
            </div>
            <Button size="sm" class="gap-1.5" as-child data-benchmark-case-new>
                <Link :href="BenchmarkCaseController.create.url()">
                    <Plus class="size-3.5" />New case
                </Link>
            </Button>
        </div>

        <form
            class="flex flex-wrap items-end gap-3"
            data-benchmark-case-filters
            @submit.prevent="applyFilters"
        >
            <label class="grid gap-1 text-[12px] text-muted-foreground">
                Task
                <select
                    v-model="taskFilter"
                    class="h-8 rounded-md border border-input bg-background px-2 text-sm text-foreground"
                    data-benchmark-filter-task
                    @change="applyFilters"
                >
                    <option value="">All tasks</option>
                    <option
                        v-for="task in tasks"
                        :key="task.value"
                        :value="task.value"
                    >
                        {{ task.name }}
                    </option>
                </select>
            </label>
            <label class="grid gap-1 text-[12px] text-muted-foreground">
                Tag
                <Input
                    v-model="tagFilter"
                    class="h-8 w-44 text-sm"
                    placeholder="Any tag"
                    data-benchmark-filter-tag
                    @blur="applyFilters"
                />
            </label>
        </form>

        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Task</TableHead>
                        <TableHead>Name</TableHead>
                        <TableHead>Tags</TableHead>
                        <TableHead class="text-right">Fixtures</TableHead>
                        <TableHead>Scoring</TableHead>
                        <TableHead>Source</TableHead>
                        <TableHead class="w-[1%]" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="benchmarkCase in cases.data"
                        :key="benchmarkCase.id"
                        class="cursor-pointer"
                        :data-benchmark-case-row="benchmarkCase.id"
                        @click="
                            router.visit(
                                BenchmarkCaseController.edit.url(
                                    benchmarkCase.id,
                                ),
                            )
                        "
                    >
                        <TableCell class="whitespace-nowrap">{{
                            benchmarkCase.task_label
                        }}</TableCell>
                        <TableCell class="font-medium">{{
                            benchmarkCase.name
                        }}</TableCell>
                        <TableCell>
                            <div class="flex flex-wrap gap-1">
                                <Pill
                                    v-for="tag in benchmarkCase.tags"
                                    :key="tag"
                                    >{{ tag }}</Pill
                                >
                            </div>
                        </TableCell>
                        <TableCell class="text-right tabular-nums">{{
                            benchmarkCase.fixture_count
                        }}</TableCell>
                        <TableCell>{{
                            SCORING_LABELS[benchmarkCase.scoring_type ?? ''] ??
                            '—'
                        }}</TableCell>
                        <TableCell class="text-[12.5px] text-muted-foreground">
                            <template v-if="benchmarkCase.source_invocation_id"
                                >from usage #{{
                                    benchmarkCase.source_invocation_id
                                }}</template
                            >
                            <template v-else>hand-written</template>
                        </TableCell>
                        <TableCell class="whitespace-nowrap" @click.stop>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-7 gap-1 text-xs"
                                as-child
                            >
                                <Link
                                    :href="
                                        BenchmarkCaseController.edit.url(
                                            benchmarkCase.id,
                                        )
                                    "
                                >
                                    <Pencil class="size-3.5" />Edit
                                </Link>
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-7 gap-1 text-xs text-destructive"
                                data-benchmark-case-delete
                                @click="remove(benchmarkCase)"
                            >
                                <Trash2 class="size-3.5" />Delete
                            </Button>
                        </TableCell>
                    </TableRow>
                    <TableEmpty v-if="cases.data.length === 0" :colspan="7">
                        No benchmark cases match this filter.
                    </TableEmpty>
                </TableBody>
            </Table>
        </div>

        <div
            v-if="cases.meta.last_page > 1"
            class="flex items-center justify-end gap-2"
        >
            <Button
                v-for="(link, i) in cases.links"
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
