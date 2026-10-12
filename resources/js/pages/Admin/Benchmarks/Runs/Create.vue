<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Layers, Plus, TriangleAlert, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BenchmarkRunController from '@/actions/App/Http/Controllers/Admin/BenchmarkRunController';
import ModelSelect from '@/components/ai/ModelSelect.vue';
import ReasoningSelect from '@/components/ai/ReasoningSelect.vue';
import { formatUsd } from '@/components/benchmarks';
import type {
    BenchmarkEstimate,
    BenchmarkRunCaseOption,
    BenchmarkTaskOption,
} from '@/components/benchmarks';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { jsonRequest } from '@/lib/http';
import { dashboard } from '@/routes';
import type { AiReasoningLevel } from '@/typefinder';

type Selection = {
    provider: string;
    model: string;
    reasoning: AiReasoningLevel | null;
};

const MAX_CANDIDATES = 8;

const props = defineProps<{
    cases: BenchmarkRunCaseOption[];
    tasks: BenchmarkTaskOption[];
    models: Record<string, string[]>;
    providers: string[];
    reasoningLevels: Array<{ label: string; value: AiReasoningLevel }>;
    modelCapabilities: Record<
        string,
        {
            supports_reasoning: boolean | null;
            levels: AiReasoningLevel[] | null;
        }
    >;
    reasoningProviders: string[];
    judgeDefault: { provider: string; model: string; reasoning: string };
    currentTiers: Record<
        string,
        Array<{ provider: string; model: string; reasoning: string }>
    >;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'Benchmarks', href: BenchmarkRunController.index.url() },
            { title: 'New run', href: BenchmarkRunController.create.url() },
        ],
    },
});

const name = ref('');
const selectedCaseIds = ref<number[]>([]);
const tagFilter = ref('');
const candidates = ref<Selection[]>([]);
const judge = ref<Selection>({
    provider: props.judgeDefault.provider,
    model: props.judgeDefault.model,
    reasoning: props.judgeDefault.reasoning as AiReasoningLevel,
});
const costCap = ref('');

const estimate = ref<BenchmarkEstimate | null>(null);
const estimating = ref(false);
const starting = ref(false);
const estimateError = ref<string | null>(null);
const errors = ref<Record<string, string>>({});

const allTags = computed(() =>
    [
        ...new Set(props.cases.flatMap((benchmarkCase) => benchmarkCase.tags)),
    ].sort(),
);

const visibleCases = computed(() =>
    props.cases.filter(
        (benchmarkCase) =>
            tagFilter.value === '' ||
            benchmarkCase.tags.includes(tagFilter.value),
    ),
);

const groups = computed(() =>
    props.tasks
        .map((task) => ({
            task,
            cases: visibleCases.value.filter(
                (benchmarkCase) => benchmarkCase.task === task.value,
            ),
        }))
        .filter((group) => group.cases.length > 0),
);

const selectedTasks = computed(() => [
    ...new Set(
        props.cases
            .filter((benchmarkCase) =>
                selectedCaseIds.value.includes(benchmarkCase.id),
            )
            .map((benchmarkCase) => benchmarkCase.task),
    ),
]);

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

function addCandidate(): void {
    if (candidates.value.length >= MAX_CANDIDATES) {
        return;
    }

    candidates.value.push({
        provider: '',
        model: '',
        reasoning: 'provider_default',
    });
}

function addCurrentTiers(): void {
    for (const task of selectedTasks.value) {
        for (const tier of props.currentTiers[task] ?? []) {
            const exists = candidates.value.some(
                (candidate) =>
                    candidate.provider === tier.provider &&
                    candidate.model === tier.model &&
                    candidate.reasoning === tier.reasoning,
            );

            if (!exists && candidates.value.length < MAX_CANDIDATES) {
                candidates.value.push({
                    provider: tier.provider,
                    model: tier.model,
                    reasoning: tier.reasoning as AiReasoningLevel,
                });
            }
        }
    }
}

function removeCandidate(index: number): void {
    candidates.value.splice(index, 1);
}

function reasoningHint(selection: Selection): string | null {
    if (selection.model === '') {
        return null;
    }

    if (!props.reasoningProviders.includes(selection.provider)) {
        return 'Not supported by this provider';
    }

    if (
        props.modelCapabilities[`${selection.provider}|${selection.model}`]
            ?.supports_reasoning === false
    ) {
        return "Model doesn't reason";
    }

    return null;
}

function acceptedLevels(selection: Selection): AiReasoningLevel[] | null {
    return (
        props.modelCapabilities[`${selection.provider}|${selection.model}`]
            ?.levels ?? null
    );
}

const complete = (selection: Selection): boolean =>
    selection.provider !== '' && selection.model !== '';

const canEstimate = computed(
    () =>
        selectedCaseIds.value.length > 0 &&
        candidates.value.length > 0 &&
        candidates.value.every(complete) &&
        complete(judge.value),
);

const costCapValue = computed(() =>
    costCap.value.trim() === '' ? null : Number(costCap.value),
);

function selectionPayload(selection: Selection): Selection {
    return {
        provider: selection.provider,
        model: selection.model,
        reasoning: selection.reasoning,
    };
}

const estimateInputs = computed(() => ({
    case_ids: selectedCaseIds.value,
    candidates: candidates.value.map(selectionPayload),
    judge: selectionPayload(judge.value),
    cost_cap_usd: costCapValue.value,
}));

watch(estimateInputs, () => {
    estimate.value = null;
    estimateError.value = null;
});

const perCandidate = computed(() =>
    candidates.value.map((candidate, index) => ({
        candidate,
        usd: (estimate.value?.rows ?? [])
            .filter((row) => row.candidate_index === index)
            .reduce((sum, row) => sum + row.usd, 0),
    })),
);

async function runEstimate(): Promise<void> {
    estimating.value = true;
    estimateError.value = null;
    errors.value = {};

    try {
        estimate.value = await jsonRequest<BenchmarkEstimate>(
            'POST',
            BenchmarkRunController.estimate.url(),
            { ...estimateInputs.value, name: name.value || null },
        );
    } catch (error) {
        estimate.value = null;
        estimateError.value =
            error instanceof Error ? error.message : 'Estimate failed.';
    } finally {
        estimating.value = false;
    }
}

function start(): void {
    if (estimate.value === null) {
        return;
    }

    router.post(
        BenchmarkRunController.store.url(),
        {
            ...estimateInputs.value,
            name: name.value,
            confirm_estimate_usd: estimate.value.total_usd,
        },
        {
            onStart: () => {
                starting.value = true;
                errors.value = {};
            },
            onError: (bag) => {
                errors.value = bag;
            },
            onFinish: () => {
                starting.value = false;
            },
        },
    );
}

const errorMessages = computed(() => Object.values(errors.value));
</script>

<template>
    <Head title="New benchmark run" />

    <div class="flex max-w-4xl flex-col gap-6 p-5">
        <div>
            <h1 class="text-[20px] font-semibold">New benchmark run</h1>
            <p class="mt-1 text-[12.5px] text-muted-foreground">
                Pick cases and candidate models, estimate the cost, then start.
            </p>
        </div>

        <section class="grid gap-1.5">
            <label class="text-[13px] font-semibold" for="benchmark-run-name"
                >Name</label
            >
            <Input
                id="benchmark-run-name"
                v-model="name"
                class="max-w-md"
                placeholder="Spring bake-off"
                data-benchmark-run-name
            />
            <InputError :message="errors.name" />
        </section>

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
            <InputError :message="errors.case_ids" />
        </section>

        <section class="grid gap-3" data-benchmark-candidates>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-[13px] font-semibold">
                    Candidates
                    <span class="font-normal text-muted-foreground"
                        >({{ candidates.length }} / {{ MAX_CANDIDATES }})</span
                    >
                </h2>
                <div class="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="gap-1.5"
                        :disabled="selectedTasks.length === 0"
                        data-benchmark-add-tiers
                        @click="addCurrentTiers"
                    >
                        <Layers class="size-3.5" />Add current tiers
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="gap-1.5"
                        :disabled="candidates.length >= MAX_CANDIDATES"
                        data-benchmark-add-candidate
                        @click="addCandidate"
                    >
                        <Plus class="size-3.5" />Add candidate
                    </Button>
                </div>
            </div>
            <p
                v-if="candidates.length === 0"
                class="text-[12.5px] text-muted-foreground"
            >
                Add at least one candidate model.
            </p>
            <div
                v-for="(candidate, index) in candidates"
                :key="index"
                class="flex flex-wrap items-start gap-2 rounded-lg border border-border bg-card p-3"
                :data-benchmark-candidate="index"
            >
                <ModelSelect
                    v-model:provider="candidate.provider"
                    data-benchmark-model-select
                    v-model:model="candidate.model"
                    :models="models"
                />
                <ReasoningSelect
                    v-model="candidate.reasoning"
                    :levels="reasoningLevels"
                    :accepted="acceptedLevels(candidate)"
                    :disabled-hint="reasoningHint(candidate)"
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="ml-auto"
                    :aria-label="`Remove candidate ${index + 1}`"
                    data-benchmark-remove-candidate
                    @click="removeCandidate(index)"
                >
                    <Trash2 class="size-4" />
                </Button>
            </div>
            <InputError :message="errors.candidates" />
        </section>

        <section class="grid gap-2" data-benchmark-judge-section>
            <h2 class="text-[13px] font-semibold">Judge</h2>
            <div class="flex flex-wrap items-start gap-2">
                <ModelSelect
                    v-model:provider="judge.provider"
                    v-model:model="judge.model"
                    :models="models"
                />
                <ReasoningSelect
                    v-model="judge.reasoning"
                    :levels="reasoningLevels"
                    :accepted="acceptedLevels(judge)"
                    :disabled-hint="reasoningHint(judge)"
                />
            </div>
            <InputError :message="errors['judge.model'] ?? errors.judge" />
        </section>

        <section class="grid gap-1.5">
            <label class="text-[13px] font-semibold" for="benchmark-cost-cap"
                >Cost cap (USD, optional)</label
            >
            <Input
                id="benchmark-cost-cap"
                v-model="costCap"
                type="number"
                min="0"
                step="0.01"
                class="max-w-[160px]"
                placeholder="No cap"
                data-benchmark-cost-cap
            />
            <InputError :message="errors.cost_cap_usd" />
        </section>

        <section
            v-if="estimate"
            class="grid gap-2 rounded-lg border border-border bg-card p-4"
            data-benchmark-estimate
        >
            <p class="text-[13px]">
                Estimated total
                <strong class="tabular-nums" data-benchmark-estimate-total>{{
                    formatUsd(estimate.total_usd)
                }}</strong>
                <span class="text-muted-foreground">
                    (judge {{ formatUsd(estimate.judge_usd) }})</span
                >
            </p>
            <ul class="grid gap-0.5 text-[12.5px] text-muted-foreground">
                <li
                    v-for="(row, index) in perCandidate"
                    :key="index"
                    class="tabular-nums"
                >
                    {{ row.candidate.model }}: {{ formatUsd(row.usd) }}
                </li>
            </ul>
            <p
                v-if="estimate.unpriced.length > 0"
                class="flex items-start gap-1.5 text-[12.5px] text-warning"
                data-benchmark-estimate-unpriced
            >
                <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
                No price for {{ estimate.unpriced.join(', ') }} — counted as $0.
            </p>
            <p
                v-if="
                    costCapValue !== null && estimate.total_usd > costCapValue
                "
                class="text-[12.5px] text-warning"
            >
                The estimate is above the cost cap; the run may stop early.
            </p>
        </section>

        <div
            v-if="estimateError || errorMessages.length > 0"
            class="rounded-md border border-destructive/30 bg-destructive/10 p-3 text-[12.5px] text-destructive"
            data-benchmark-run-errors
        >
            <p v-if="estimateError">{{ estimateError }}</p>
            <p v-for="message in errorMessages" :key="message">
                {{ message }}
            </p>
        </div>

        <div class="flex gap-2">
            <Button
                type="button"
                variant="outline"
                :disabled="!canEstimate || estimating"
                data-benchmark-estimate-button
                @click="runEstimate"
            >
                {{ estimating ? 'Estimating…' : 'Estimate' }}
            </Button>
            <Button
                type="button"
                :disabled="estimate === null || starting || name.trim() === ''"
                data-benchmark-start
                @click="start"
            >
                Start run
            </Button>
        </div>
    </div>
</template>
