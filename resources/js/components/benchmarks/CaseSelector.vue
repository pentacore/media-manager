<script setup lang="ts">
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import type { BenchmarkRunCaseOption, BenchmarkTaskOption } from './types';

const props = defineProps<{
    cases: BenchmarkRunCaseOption[];
    tasks: BenchmarkTaskOption[];
    error?: string;
}>();

const selectedCaseIds = defineModel<number[]>({ required: true });

const tagFilter = ref('');

const allTags = computed(() =>
    [
        ...new Set(props.cases.flatMap((benchmarkCase) => benchmarkCase.tags)),
    ].sort(),
);

const groups = computed(() =>
    props.tasks
        .map((task) => ({
            task,
            cases: props.cases.filter(
                (benchmarkCase) =>
                    benchmarkCase.task === task.value &&
                    (tagFilter.value === '' ||
                        benchmarkCase.tags.includes(tagFilter.value)),
            ),
        }))
        .filter((group) => group.cases.length > 0),
);

function allSelected(cases: BenchmarkRunCaseOption[]): boolean {
    return cases.every((benchmarkCase) =>
        selectedCaseIds.value.includes(benchmarkCase.id),
    );
}

function toggleTask(cases: BenchmarkRunCaseOption[]): void {
    const ids = cases.map((benchmarkCase) => benchmarkCase.id);

    selectedCaseIds.value = allSelected(cases)
        ? selectedCaseIds.value.filter((id) => !ids.includes(id))
        : [...new Set([...selectedCaseIds.value, ...ids])];
}
</script>

<template>
    <section class="grid gap-3" data-benchmark-cases>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <h2 class="text-[13px] font-semibold">
                Cases
                <span class="font-normal text-muted-foreground"
                    >({{ selectedCaseIds.length }} selected)</span
                >
            </h2>
            <label
                v-if="allTags.length > 0"
                class="flex items-center gap-2 text-[12px] text-muted-foreground"
            >
                Tag
                <select
                    v-model="tagFilter"
                    class="h-8 rounded-md border border-input bg-background px-2 text-sm text-foreground"
                    data-benchmark-tag-filter
                >
                    <option value="">All tags</option>
                    <option v-for="tag in allTags" :key="tag" :value="tag">
                        {{ tag }}
                    </option>
                </select>
            </label>
        </div>
        <p
            v-if="cases.length === 0"
            class="text-[12.5px] text-muted-foreground"
        >
            No cases yet — write some on the Cases page first.
        </p>
        <div
            v-for="group in groups"
            :key="group.task.value"
            class="rounded-lg border border-border bg-card"
            :data-benchmark-case-group="group.task.value"
        >
            <label
                class="flex items-center gap-2 border-b border-border px-3 py-2 text-[12.5px] font-semibold"
            >
                <input
                    type="checkbox"
                    class="size-4 accent-primary"
                    :checked="allSelected(group.cases)"
                    data-benchmark-select-task
                    @change="toggleTask(group.cases)"
                />
                {{ group.task.name }}
            </label>
            <div class="grid gap-1 p-3">
                <label
                    v-for="benchmarkCase in group.cases"
                    :key="benchmarkCase.id"
                    class="flex items-center gap-2 text-[13px]"
                >
                    <input
                        v-model="selectedCaseIds"
                        type="checkbox"
                        class="size-4 accent-primary"
                        :value="benchmarkCase.id"
                        :data-benchmark-case-option="benchmarkCase.id"
                    />
                    {{ benchmarkCase.name }}
                    <span
                        v-if="benchmarkCase.tags.length > 0"
                        class="text-[11.5px] text-muted-foreground"
                        >{{ benchmarkCase.tags.join(', ') }}</span
                    >
                </label>
            </div>
        </div>
        <InputError :message="error" />
    </section>
</template>
