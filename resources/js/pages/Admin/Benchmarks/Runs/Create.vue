<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BenchmarkRunController from '@/actions/App/Http/Controllers/Admin/BenchmarkRunController';
import {
    CandidateRows,
    CaseSelector,
    EstimateSummary,
    SelectionRow,
} from '@/components/benchmarks';
import type {
    BenchmarkEstimate,
    BenchmarkModelCapabilities,
    BenchmarkRunCaseOption,
    BenchmarkSelectionForm,
    BenchmarkTaskOption,
} from '@/components/benchmarks';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { jsonRequest } from '@/lib/http';
import { dashboard } from '@/routes';
import type { AiReasoningLevel } from '@/typefinder';

const MAX_CANDIDATES = 8;

const props = defineProps<{
    cases: BenchmarkRunCaseOption[];
    tasks: BenchmarkTaskOption[];
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
const candidates = ref<BenchmarkSelectionForm[]>([]);
const judge = ref<BenchmarkSelectionForm>({
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

const selectedTasks = computed(() => [
    ...new Set(
        props.cases
            .filter((benchmarkCase) =>
                selectedCaseIds.value.includes(benchmarkCase.id),
            )
            .map((benchmarkCase) => benchmarkCase.task),
    ),
]);

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

const complete = (selection: BenchmarkSelectionForm): boolean =>
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

function selectionPayload(
    selection: BenchmarkSelectionForm,
): BenchmarkSelectionForm {
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
    errors.value = {};

    try {
        const response = await jsonRequest<BenchmarkEstimate>(
            'POST',
            BenchmarkRunController.estimate.url(),
            { ...estimateInputs.value, name: name.value || null },
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

        <CaseSelector
            v-model="selectedCaseIds"
            :cases="cases"
            :tasks="tasks"
            :error="errors.case_ids"
        />

        <CandidateRows
            v-model="candidates"
            :models="models"
            :reasoning-levels="reasoningLevels"
            :model-capabilities="modelCapabilities"
            :reasoning-providers="reasoningProviders"
            :can-add-tiers="selectedTasks.length > 0"
            :error="errors.candidates"
            @add-tiers="addCurrentTiers"
        />

        <section class="grid gap-2" data-benchmark-judge-section>
            <h2 class="text-[13px] font-semibold">Judge</h2>
            <SelectionRow
                v-model="judge"
                :models="models"
                :reasoning-levels="reasoningLevels"
                :model-capabilities="modelCapabilities"
                :reasoning-providers="reasoningProviders"
            />
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

        <EstimateSummary
            v-if="estimate"
            :estimate="estimate"
            :candidates="candidates"
            :cost-cap="costCapValue"
        />

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
