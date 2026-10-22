<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Ban, Scale, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BenchmarkComparisonController from '@/actions/App/Http/Controllers/Admin/BenchmarkComparisonController';
import { SegmentedControl } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useConfirm } from '@/composables/useConfirm';
import { jsonRequest } from '@/lib/http';
import type { AiReasoningLevel } from '@/typefinder';
import { ORIGINAL_SCORES } from './compare';
import { formatUsd } from './format';
import SelectionRow from './SelectionRow.vue';
import type {
    BenchmarkComparison,
    BenchmarkJudgeOptions,
    BenchmarkNeutralEstimate,
    BenchmarkNeutralRejudgeSummary,
    BenchmarkSelectionForm,
} from './types';

const props = defineProps<{
    comparison: BenchmarkComparison;
    rejudge: BenchmarkNeutralRejudgeSummary | null;
    judgeOptions: BenchmarkJudgeOptions;
}>();

const emit = defineEmits<{
    scores: [key: string];
}>();

const running = computed(() => props.rejudge?.status === 'running');

const judgeLabels = computed(() => [
    ...new Set(
        props.comparison.runs
            .map((run) => run.judge_label)
            .filter((label): label is string => label !== null),
    ),
]);

const scoreOptions = computed(() => [
    { value: ORIGINAL_SCORES, label: 'Original judges' },
    ...props.comparison.score_source.options.map((option) => ({
        value: option.key,
        label: `Neutral · ${option.label} · ${Math.round(option.coverage * 100)}%`,
    })),
]);

const scoreSource = computed({
    get: () => props.comparison.score_source.current,
    set: (key: string) => emit('scores', key),
});

const { confirm } = useConfirm();

async function cancel(): Promise<void> {
    if (props.rejudge === null) {
        return;
    }

    const confirmed = await confirm({
        title: 'Cancel the neutral re-judge?',
        description:
            'Queued results are left unscored; finished ones keep their score.',
        confirmLabel: 'Cancel re-judge',
        destructive: true,
    });

    if (confirmed) {
        router.post(
            BenchmarkComparisonController.cancel.url(props.rejudge.id),
            {},
            { preserveScroll: true },
        );
    }
}

const dialogOpen = ref(false);
const judge = ref<BenchmarkSelectionForm>({
    provider: props.judgeOptions.judgeDefault.provider,
    model: props.judgeOptions.judgeDefault.model,
    reasoning: props.judgeOptions.judgeDefault.reasoning as AiReasoningLevel,
});
const costCap = ref<string | number>('');
const estimate = ref<BenchmarkNeutralEstimate | null>(null);
const estimating = ref(false);
const starting = ref(false);
const errors = ref<string[]>([]);

const runIds = computed(() => props.comparison.runs.map((run) => run.id));
const judgePayload = computed(() => ({
    provider: judge.value.provider,
    model: judge.value.model,
    reasoning: judge.value.reasoning,
}));

/** Bumped on every judge change so a late estimate is dropped. */
let judgeVersion = 0;

watch(judgePayload, () => {
    judgeVersion++;
    estimate.value = null;
});

async function runEstimate(): Promise<void> {
    const version = judgeVersion;

    estimating.value = true;
    errors.value = [];

    try {
        const response = await jsonRequest<BenchmarkNeutralEstimate>(
            'POST',
            BenchmarkComparisonController.estimate.url(),
            { run_ids: runIds.value, judge: judgePayload.value },
        );

        if (version === judgeVersion) {
            estimate.value = response;
        }
    } catch (error) {
        if (version === judgeVersion) {
            errors.value = [
                error instanceof Error ? error.message : 'Estimate failed.',
            ];
        }
    } finally {
        estimating.value = false;
    }
}

const canStart = computed(
    () =>
        estimate.value !== null &&
        estimate.value.results > 0 &&
        String(costCap.value).trim() !== '' &&
        !starting.value,
);

function start(): void {
    router.post(
        BenchmarkComparisonController.start.url(),
        {
            run_ids: runIds.value,
            judge: judgePayload.value,
            cost_cap_usd: Number(costCap.value),
        },
        {
            preserveScroll: true,
            onStart: () => {
                starting.value = true;
                errors.value = [];
            },
            onError: (bag) => {
                errors.value = Object.values(bag);
            },
            onSuccess: () => {
                dialogOpen.value = false;
                estimate.value = null;
            },
            onFinish: () => {
                starting.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="grid min-w-0 gap-3" data-compare-neutral-panel>
        <div
            v-if="comparison.judges_differ"
            class="flex items-start gap-2 rounded-xl border border-warning/40 bg-warning/10 p-3 text-[13px]"
            data-compare-judges-differ
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0 text-warning" />
            <p>
                These runs used different judges ({{ judgeLabels.join(', ') }}).
                Score and pass-rate deltas may reflect the judge, not the model
                — re-judge both with one neutral judge to compare them fairly.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="text-[12.5px] font-semibold">Scores</span>
            <SegmentedControl
                v-model="scoreSource"
                :options="scoreOptions"
                option-attribute="compare-score-source"
            />
            <Button
                variant="outline"
                size="sm"
                class="gap-1.5"
                :disabled="running"
                data-compare-neutral-open
                @click="dialogOpen = true"
            >
                <Scale class="size-3.5" />Re-judge with a neutral judge…
            </Button>
        </div>

        <div
            v-if="rejudge"
            class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px] text-muted-foreground tabular-nums"
            data-compare-rejudge-status
        >
            <span
                >Neutral re-judge ({{ rejudge.judge_label }}):
                <strong class="text-foreground">{{
                    rejudge.status_label
                }}</strong></span
            >
            <span>{{ rejudge.done }} / {{ rejudge.total }} judged</span>
            <span v-if="rejudge.failed > 0" class="text-destructive"
                >{{ rejudge.failed }} failed</span
            >
            <span
                >{{ formatUsd(rejudge.cost_usd) }} of
                {{ formatUsd(rejudge.cost_cap_usd) }} cap</span
            >
            <Button
                v-if="running"
                variant="ghost"
                size="sm"
                class="h-7 gap-1 text-xs"
                data-compare-rejudge-cancel
                @click="cancel"
            >
                <Ban class="size-3.5" />Cancel
            </Button>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="sm:max-w-2xl" data-compare-neutral-dialog>
                <DialogHeader>
                    <DialogTitle>Re-judge with a neutral judge</DialogTitle>
                    <DialogDescription>
                        One judge re-scores the judged results of every compared
                        run, on the cases they share, against each case's
                        current rubric. The runs' own scores are kept.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-4">
                    <section class="grid gap-2">
                        <h3 class="text-[13px] font-semibold">Judge</h3>
                        <SelectionRow
                            v-model="judge"
                            :models="judgeOptions.models"
                            :reasoning-levels="judgeOptions.reasoningLevels"
                            :model-capabilities="judgeOptions.modelCapabilities"
                            :reasoning-providers="
                                judgeOptions.reasoningProviders
                            "
                        />
                    </section>
                    <div class="flex flex-wrap items-center gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="estimating || judge.model === ''"
                            data-compare-neutral-estimate
                            @click="runEstimate"
                            >{{
                                estimating ? 'Estimating…' : 'Estimate'
                            }}</Button
                        >
                        <p
                            v-if="estimate"
                            class="text-[12.5px] tabular-nums"
                            data-compare-neutral-estimate-total
                        >
                            {{ estimate.results }} results · est.
                            {{ formatUsd(estimate.total_usd) }}
                            <span
                                v-if="estimate.unpriced.length > 0"
                                class="text-warning"
                                >· unpriced:
                                {{ estimate.unpriced.join(', ') }}</span
                            >
                        </p>
                    </div>
                    <section class="grid gap-1.5">
                        <label
                            class="text-[13px] font-semibold"
                            for="compare-neutral-cap"
                            >Cost cap (USD)</label
                        >
                        <Input
                            id="compare-neutral-cap"
                            v-model="costCap"
                            type="number"
                            min="0"
                            step="0.01"
                            class="max-w-[160px]"
                            data-compare-neutral-cap
                        />
                    </section>
                    <div
                        v-if="errors.length > 0"
                        class="rounded-md border border-destructive/30 bg-destructive/10 p-3 text-[12.5px] text-destructive"
                        data-compare-neutral-errors
                    >
                        <p v-for="message in errors" :key="message">
                            {{ message }}
                        </p>
                    </div>
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        :disabled="!canStart"
                        data-compare-neutral-start
                        @click="start"
                        >Start re-judge</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
