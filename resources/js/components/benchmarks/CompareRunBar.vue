<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeftRight, Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import BenchmarkRunController from '@/actions/App/Http/Controllers/Admin/BenchmarkRunController';
import { Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { BenchmarkComparisonRun, BenchmarkPickableRun } from './types';

const MAX_RUNS = 5;

const props = defineProps<{
    runs: BenchmarkComparisonRun[];
    pickableRuns: BenchmarkPickableRun[];
}>();

const emit = defineEmits<{
    change: [runs: number[], baseline: number];
}>();

const runIds = computed(() => props.runs.map((run) => run.id));
const baselineId = computed(
    () => props.runs.find((run) => run.is_baseline)?.id ?? runIds.value[0],
);
const addable = computed(() =>
    props.pickableRuns.filter((run) => !runIds.value.includes(run.id)),
);

function setBaseline(id: number): void {
    emit('change', runIds.value, id);
}

function remove(id: number): void {
    const next = runIds.value.filter((runId) => runId !== id);

    emit(
        'change',
        next,
        id === baselineId.value ? Math.min(...next) : baselineId.value,
    );
}

function add(id: number): void {
    emit('change', [...runIds.value, id], baselineId.value);
}

function swap(): void {
    const other = runIds.value.find((id) => id !== baselineId.value);

    if (other !== undefined) {
        emit('change', runIds.value, other);
    }
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2" data-compare-run-bar>
        <div
            v-for="run in runs"
            :key="run.id"
            class="flex min-w-0 items-center gap-1.5 rounded-lg border border-border bg-card px-2.5 py-1.5 text-[12.5px]"
            :data-compare-run="run.id"
        >
            <Link
                :href="BenchmarkRunController.show.url(run.id)"
                class="truncate font-medium hover:underline"
                >{{ run.name }}</Link
            >
            <Pill v-if="run.is_baseline" variant="info" data-compare-baseline
                >baseline</Pill
            >
            <Button
                v-else
                variant="ghost"
                size="sm"
                class="h-6 px-1.5 text-[11.5px]"
                data-compare-set-baseline
                @click="setBaseline(run.id)"
                >Set as baseline</Button
            >
            <Button
                v-if="runs.length > 2"
                variant="ghost"
                size="icon"
                class="size-6"
                :aria-label="`Remove ${run.name}`"
                data-compare-remove-run
                @click="remove(run.id)"
            >
                <X class="size-3.5" />
            </Button>
        </div>
        <Button
            v-if="runs.length === 2"
            variant="outline"
            size="sm"
            class="gap-1.5"
            data-compare-swap
            @click="swap"
        >
            <ArrowLeftRight class="size-3.5" />Swap
        </Button>
        <DropdownMenu v-if="runs.length < MAX_RUNS && addable.length > 0">
            <DropdownMenuTrigger as-child>
                <Button
                    variant="outline"
                    size="sm"
                    class="gap-1.5"
                    data-compare-add-run
                >
                    <Plus class="size-3.5" />Add run
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="max-h-80 overflow-y-auto">
                <DropdownMenuItem
                    v-for="run in addable"
                    :key="run.id"
                    :data-compare-add-option="run.id"
                    @select="add(run.id)"
                >
                    <span class="truncate">{{ run.name }}</span>
                    <span class="ml-2 text-[11.5px] text-muted-foreground">{{
                        run.task_labels.join(', ')
                    }}</span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
