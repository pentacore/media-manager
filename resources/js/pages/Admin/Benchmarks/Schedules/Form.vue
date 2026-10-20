<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BenchmarkRunController from '@/actions/App/Http/Controllers/Admin/BenchmarkRunController';
import BenchmarkScheduleController from '@/actions/App/Http/Controllers/Admin/BenchmarkScheduleController';
import {
    ScheduleCandidatesPreview,
    SelectionRow,
} from '@/components/benchmarks';
import type {
    BenchmarkEstimate,
    BenchmarkModelCapabilities,
    BenchmarkScheduleForm,
    BenchmarkSelectionForm,
    BenchmarkTaskOption,
} from '@/components/benchmarks';
import InputError from '@/components/InputError.vue';
import { Toggle } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { jsonRequest } from '@/lib/http';
import { dashboard } from '@/routes';
import type { AiReasoningLevel } from '@/typefinder';

const props = defineProps<{
    schedule: (BenchmarkScheduleForm & { id: number }) | null;
    tasks: BenchmarkTaskOption[];
    cases: Array<{ id: number; task: string; tags: string[] }>;
    frequencies: Array<{ label: string; value: string }>;
    weekdays: Array<{ label: string; value: number }>;
    models: Record<string, string[]>;
    providers: string[];
    reasoningLevels: Array<{ label: string; value: AiReasoningLevel }>;
    modelCapabilities: BenchmarkModelCapabilities;
    reasoningProviders: string[];
    judgeDefault: { provider: string; model: string; reasoning: string };
    currentTiers: Record<
        string,
        Array<{ provider: string; model: string; reasoning: string }>
    >;
}>();

const isEditing = props.schedule !== null;

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

const form = useForm<BenchmarkScheduleForm>({
    name: props.schedule?.name ?? '',
    task: props.schedule?.task ?? props.tasks[0]?.value ?? '',
    case_tags: [...(props.schedule?.case_tags ?? [])],
    judge: {
        provider: props.schedule?.judge.provider ?? props.judgeDefault.provider,
        model: props.schedule?.judge.model ?? props.judgeDefault.model,
        reasoning: (props.schedule?.judge.reasoning ??
            props.judgeDefault.reasoning) as AiReasoningLevel,
    },
    cost_cap_usd: props.schedule?.cost_cap_usd ?? 1,
    frequency: props.schedule?.frequency ?? 'daily',
    time_of_day: props.schedule?.time_of_day ?? '03:00',
    weekday: props.schedule?.weekday ?? 1,
    day_of_month: props.schedule?.day_of_month ?? 1,
    pass_rate_threshold: props.schedule?.pass_rate_threshold ?? 10,
    score_threshold: props.schedule?.score_threshold ?? 10,
    enabled: props.schedule?.enabled ?? true,
});

const taskCases = computed(() =>
    props.cases.filter((benchmarkCase) => benchmarkCase.task === form.task),
);

const taskTags = computed(() =>
    [
        ...new Set(
            taskCases.value.flatMap((benchmarkCase) => benchmarkCase.tags),
        ),
    ].sort(),
);

const matchingCases = computed(() =>
    taskCases.value.filter(
        (benchmarkCase) =>
            form.case_tags.length === 0 ||
            benchmarkCase.tags.some((tag) => form.case_tags.includes(tag)),
    ),
);

const candidates = computed(() => props.currentTiers[form.task] ?? []);

function changeTask(task: unknown): void {
    if (typeof task !== 'string' || task === form.task) {
        return;
    }

    form.task = task;
    form.case_tags = [];
}

function toggleTag(tag: string): void {
    form.case_tags = form.case_tags.includes(tag)
        ? form.case_tags.filter((selected) => selected !== tag)
        : [...form.case_tags, tag];
}

const estimate = ref<BenchmarkEstimate | null>(null);
const estimating = ref(false);
const estimateError = ref<string | null>(null);

const costCapValue = computed(() => {
    const value = Number(form.cost_cap_usd);

    return Number.isFinite(value) ? value : null;
});

const canEstimate = computed(
    () =>
        matchingCases.value.length > 0 &&
        candidates.value.length > 0 &&
        form.judge.provider !== '' &&
        form.judge.model !== '',
);

const estimateInputs = computed(() => ({
    case_ids: matchingCases.value.map((benchmarkCase) => benchmarkCase.id),
    candidates: candidates.value.map((candidate) => ({
        provider: candidate.provider,
        model: candidate.model,
        reasoning: candidate.reasoning,
    })),
    judge: {
        provider: form.judge.provider,
        model: form.judge.model,
        reasoning: form.judge.reasoning,
    },
    cost_cap_usd: costCapValue.value,
}));

/** Bumped on every input change so a late estimate response is dropped. */
let inputVersion = 0;

watch(estimateInputs, () => {
    inputVersion++;
    estimate.value = null;
    estimateError.value = null;
});

async function runEstimate(): Promise<void> {
    const version = inputVersion;

    estimating.value = true;
    estimateError.value = null;

    try {
        const response = await jsonRequest<BenchmarkEstimate>(
            'POST',
            BenchmarkRunController.estimate.url(),
            estimateInputs.value,
        );

        if (version === inputVersion) {
            estimate.value = response;
        }
    } catch (error) {
        if (version === inputVersion) {
            estimate.value = null;
            estimateError.value =
                error instanceof Error ? error.message : 'Estimate failed.';
        }
    } finally {
        estimating.value = false;
    }
}

function submit(): void {
    if (props.schedule === null) {
        form.post(BenchmarkScheduleController.store.url());

        return;
    }

    form.put(BenchmarkScheduleController.update.url(props.schedule.id));
}

function setJudge(judge: BenchmarkSelectionForm): void {
    form.judge = judge;
}
</script>

<template>
    <Head
        :title="
            isEditing ? 'Edit benchmark schedule' : 'New benchmark schedule'
        "
    />

    <form class="flex max-w-4xl flex-col gap-6 p-5" @submit.prevent="submit">
        <div>
            <h1 class="text-[20px] font-semibold">
                {{ isEditing ? 'Edit schedule' : 'New schedule' }}
            </h1>
            <p class="mt-1 text-[12.5px] text-muted-foreground">
                Re-run a task's cases against its current tier models on a
                schedule.
            </p>
        </div>

        <section class="grid gap-1.5">
            <label class="text-[13px] font-semibold" for="schedule-name"
                >Name</label
            >
            <Input
                id="schedule-name"
                v-model="form.name"
                class="max-w-md"
                placeholder="Weekly anime titles"
                data-benchmark-schedule-name
            />
            <InputError :message="form.errors.name" />
        </section>

        <section class="grid gap-1.5">
            <h2 class="text-[13px] font-semibold">Task</h2>
            <Select :model-value="form.task" @update:model-value="changeTask">
                <SelectTrigger
                    class="h-8 max-w-[260px] text-sm"
                    data-benchmark-schedule-task
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="task in tasks"
                        :key="task.value"
                        :value="task.value"
                    >
                        {{ task.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <InputError :message="form.errors.task" />
        </section>

        <section class="grid gap-2">
            <h2 class="text-[13px] font-semibold">
                Tags
                <span class="font-normal text-muted-foreground"
                    >(none selected means every case of the task)</span
                >
            </h2>
            <div v-if="taskTags.length > 0" class="flex flex-wrap gap-1.5">
                <Button
                    v-for="tag in taskTags"
                    :key="tag"
                    type="button"
                    size="sm"
                    :variant="
                        form.case_tags.includes(tag) ? 'default' : 'outline'
                    "
                    :data-benchmark-schedule-tag="tag"
                    @click="toggleTag(tag)"
                >
                    {{ tag }}
                </Button>
            </div>
            <p
                class="text-[12.5px] text-muted-foreground"
                data-benchmark-schedule-case-count
            >
                {{ matchingCases.length }}
                {{ matchingCases.length === 1 ? 'case' : 'cases' }}
            </p>
            <InputError :message="form.errors.case_tags" />
        </section>

        <section class="grid gap-2">
            <h2 class="text-[13px] font-semibold">Judge</h2>
            <SelectionRow
                :model-value="form.judge"
                :models="models"
                :reasoning-levels="reasoningLevels"
                :model-capabilities="modelCapabilities"
                :reasoning-providers="reasoningProviders"
                @update:model-value="setJudge"
            />
            <InputError
                :message="form.errors['judge.model'] ?? form.errors.judge"
            />
        </section>

        <section class="grid gap-1.5">
            <label class="text-[13px] font-semibold" for="schedule-cost-cap"
                >Cost cap (USD)</label
            >
            <Input
                id="schedule-cost-cap"
                v-model="form.cost_cap_usd"
                type="number"
                min="0"
                step="0.01"
                class="max-w-[160px]"
                data-benchmark-schedule-cap
            />
            <InputError :message="form.errors.cost_cap_usd" />
        </section>

        <ScheduleCandidatesPreview
            :candidates="candidates"
            :estimate="estimate"
            :cost-cap="costCapValue"
            :estimating="estimating"
            :can-estimate="canEstimate"
            @estimate="runEstimate"
        />
        <p
            v-if="estimateError"
            class="rounded-md border border-destructive/30 bg-destructive/10 p-3 text-[12.5px] text-destructive"
        >
            {{ estimateError }}
        </p>

        <section class="flex flex-wrap items-start gap-4">
            <div class="grid gap-1.5">
                <h2 class="text-[13px] font-semibold">Frequency</h2>
                <Select v-model="form.frequency">
                    <SelectTrigger
                        class="h-8 w-[160px] text-sm"
                        data-benchmark-schedule-frequency
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="frequency in frequencies"
                            :key="frequency.value"
                            :value="frequency.value"
                        >
                            {{ frequency.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.frequency" />
            </div>
            <div v-if="form.frequency === 'weekly'" class="grid gap-1.5">
                <h2 class="text-[13px] font-semibold">Weekday</h2>
                <Select v-model="form.weekday">
                    <SelectTrigger
                        class="h-8 w-[160px] text-sm"
                        data-benchmark-schedule-weekday
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="weekday in weekdays"
                            :key="weekday.value"
                            :value="weekday.value"
                        >
                            {{ weekday.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.weekday" />
            </div>
            <div v-if="form.frequency === 'monthly'" class="grid gap-1.5">
                <label
                    class="text-[13px] font-semibold"
                    for="schedule-day-of-month"
                    >Day of month</label
                >
                <Input
                    id="schedule-day-of-month"
                    :model-value="form.day_of_month ?? undefined"
                    @update:model-value="
                        (value) => (form.day_of_month = Number(value))
                    "
                    type="number"
                    min="1"
                    max="28"
                    class="w-[100px]"
                    data-benchmark-schedule-day
                />
                <InputError :message="form.errors.day_of_month" />
            </div>
            <div class="grid gap-1.5">
                <label class="text-[13px] font-semibold" for="schedule-time"
                    >Time</label
                >
                <Input
                    id="schedule-time"
                    v-model="form.time_of_day"
                    type="time"
                    class="w-[130px]"
                    data-benchmark-schedule-time
                />
                <InputError :message="form.errors.time_of_day" />
            </div>
        </section>

        <section class="grid gap-3">
            <div class="grid gap-1.5">
                <label
                    class="text-[13px] font-semibold"
                    for="schedule-pass-threshold"
                    >Alert when pass rate (or error rate) moves more than …
                    points</label
                >
                <Input
                    id="schedule-pass-threshold"
                    v-model="form.pass_rate_threshold"
                    type="number"
                    min="0"
                    step="0.1"
                    class="max-w-[160px]"
                    data-benchmark-schedule-pass-threshold
                />
                <InputError :message="form.errors.pass_rate_threshold" />
            </div>
            <div class="grid gap-1.5">
                <label
                    class="text-[13px] font-semibold"
                    for="schedule-score-threshold"
                    >Alert when average score drops more than … points</label
                >
                <Input
                    id="schedule-score-threshold"
                    v-model="form.score_threshold"
                    type="number"
                    min="0"
                    step="0.1"
                    class="max-w-[160px]"
                    data-benchmark-schedule-score-threshold
                />
                <InputError :message="form.errors.score_threshold" />
            </div>
        </section>

        <section class="grid gap-1.5" data-benchmark-schedule-enabled>
            <h2 class="text-[13px] font-semibold">Enabled</h2>
            <div>
                <Toggle
                    v-model="form.enabled"
                    :label="form.enabled ? 'Runs on schedule' : 'Paused'"
                />
            </div>
            <InputError :message="form.errors.enabled" />
        </section>

        <div class="flex gap-2">
            <Button
                type="submit"
                :disabled="form.processing"
                data-benchmark-schedule-save
            >
                Save schedule
            </Button>
        </div>
    </form>
</template>
