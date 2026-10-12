<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BenchmarkCaseController from '@/actions/App/Http/Controllers/Admin/BenchmarkCaseController';
import { ExpectedActionsEditor, FixtureEditor } from '@/components/benchmarks';
import type {
    BenchmarkCasePayload,
    BenchmarkExpectedAction,
    BenchmarkExpectedPrice,
    BenchmarkFixture,
    BenchmarkHistoryTurn,
    BenchmarkScoringKind,
    BenchmarkTaskOption,
} from '@/components/benchmarks';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';

const props = defineProps<{
    case: BenchmarkCasePayload | null;
    draft: BenchmarkCasePayload | null;
    draftWarnings: string[];
    tasks: BenchmarkTaskOption[];
    eventKeys: string[];
    writeTools: string[];
    defaultRubrics: Record<string, string>;
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

/** Canonical Laravel AI provider ids the price updater can be asked to refresh. */
const PRICE_PROVIDERS = [
    'openai',
    'anthropic',
    'gemini',
    'xai',
    'deepseek',
    'mistral',
    'groq',
    'cohere',
    'openrouter',
];

const SCORING_BY_TASK: Record<string, BenchmarkScoringKind> = {
    decision: 'actions',
    price_updater: 'prices',
};

const source = props.case ?? props.draft;
const editing = props.case !== null;

function blankPrice(): BenchmarkExpectedPrice {
    return {
        provider: '',
        model: '',
        input_per_mtok: '',
        output_per_mtok: '',
        cache_read_per_mtok: '',
        cache_write_per_mtok: '',
    };
}

/** The server sends null for an unset cache price; the number inputs want ''. */
function normalizePrice(
    price: Partial<
        Record<keyof BenchmarkExpectedPrice, string | number | null>
    >,
): BenchmarkExpectedPrice {
    return {
        provider: String(price.provider ?? ''),
        model: String(price.model ?? ''),
        input_per_mtok: price.input_per_mtok ?? '',
        output_per_mtok: price.output_per_mtok ?? '',
        cache_read_per_mtok: price.cache_read_per_mtok ?? '',
        cache_write_per_mtok: price.cache_write_per_mtok ?? '',
    };
}

const initialTask = source?.task ?? props.tasks[0]?.value ?? 'chat';

const form = useForm({
    task: initialTask,
    name: source?.name ?? '',
    tags: [...(source?.tags ?? [])],
    notes: source?.notes ?? '',
    input: {
        prompt: source?.input.prompt ?? '',
        history: [...(source?.input.history ?? [])] as BenchmarkHistoryTurn[],
        mode: source?.input.mode ?? '',
        event_key: source?.input.event_key ?? '',
        providers: [...(source?.input.providers ?? [])],
    },
    fixtures: [...(source?.fixtures ?? [])] as BenchmarkFixture[],
    scoring: {
        type: (source?.scoring.type ??
            SCORING_BY_TASK[initialTask] ??
            'judge') as BenchmarkScoringKind,
        actions: (source?.scoring.type === 'actions'
            ? [...(source.scoring.expected ?? [])]
            : []) as BenchmarkExpectedAction[],
        prices: (source?.scoring.type === 'prices'
            ? (source.scoring.expected ?? []).map((price) =>
                  normalizePrice(price as Partial<BenchmarkExpectedPrice>),
              )
            : []) as BenchmarkExpectedPrice[],
        tolerance_percent: source?.scoring.tolerance_percent ?? 1,
        rubric_note: source?.scoring.rubric_note ?? '',
        pass_threshold: source?.scoring.pass_threshold ?? 7,
    },
    source_invocation_id: source?.source_invocation_id ?? null,
    source_prompt_tokens: source?.source_prompt_tokens ?? null,
    source_completion_tokens: source?.source_completion_tokens ?? null,
    source_truncated: source?.source_truncated ?? false,
});

const tagsText = ref(form.tags.join(', '));

watch(tagsText, (text) => {
    form.tags = text
        .split(',')
        .map((tag) => tag.trim())
        .filter((tag) => tag !== '');
});

const scoringType = computed<BenchmarkScoringKind>(
    () => SCORING_BY_TASK[form.task] ?? 'judge',
);

watch(scoringType, (type) => {
    form.scoring.type = type;
});

const fixturesInvalid = ref(false);
const actionsInvalid = ref(false);
const blocked = ref(false);

const extraProviders = computed(() =>
    form.input.providers.filter(
        (provider) => !PRICE_PROVIDERS.includes(provider),
    ),
);

function toggleProvider(provider: string, checked: boolean): void {
    form.input.providers = checked
        ? [...form.input.providers, provider]
        : form.input.providers.filter((current) => current !== provider);
}

function addHistoryTurn(): void {
    form.input.history = [...form.input.history, { role: 'user', content: '' }];
}

function removeHistoryTurn(index: number): void {
    form.input.history = form.input.history.filter((_, i) => i !== index);
}

function addPrice(): void {
    form.scoring.prices = [...form.scoring.prices, blankPrice()];
}

function removePrice(index: number): void {
    form.scoring.prices = form.scoring.prices.filter((_, i) => i !== index);
}

function numberOrNull(
    value: number | string | null | undefined,
): number | null {
    return value === null || value === '' ? null : Number(value);
}

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

/** Only the input fields the chosen task reads are sent. */
form.transform((data) => {
    const input: Record<string, unknown> = { prompt: data.input.prompt };

    if (data.task === 'chat') {
        input.history = data.input.history;
        input.mode = data.input.mode === '' ? null : data.input.mode;
    }

    if (data.task === 'decision') {
        input.event_key =
            data.input.event_key === '' ? null : data.input.event_key;
    }

    if (data.task === 'price_updater') {
        input.providers = data.input.providers;
    }

    const scoring: Record<string, unknown> = { type: data.scoring.type };

    if (data.scoring.type === 'actions') {
        scoring.expected = data.scoring.actions;
    } else if (data.scoring.type === 'prices') {
        scoring.tolerance_percent = numberOrNull(
            data.scoring.tolerance_percent,
        );
        scoring.expected = data.scoring.prices.map((price) => ({
            provider: price.provider,
            model: price.model,
            input_per_mtok: numberOrNull(price.input_per_mtok),
            output_per_mtok: numberOrNull(price.output_per_mtok),
            cache_read_per_mtok: numberOrNull(price.cache_read_per_mtok),
            cache_write_per_mtok: numberOrNull(price.cache_write_per_mtok),
        }));
    } else {
        scoring.rubric_note =
            data.scoring.rubric_note === '' ? null : data.scoring.rubric_note;
        scoring.pass_threshold = numberOrNull(data.scoring.pass_threshold);
    }

    return {
        task: data.task,
        name: data.name,
        tags: data.tags,
        notes: data.notes === '' ? null : data.notes,
        input,
        fixtures: data.fixtures,
        scoring,
        source_invocation_id: data.source_invocation_id,
        source_prompt_tokens: data.source_prompt_tokens,
        source_completion_tokens: data.source_completion_tokens,
        source_truncated: data.source_truncated,
    };
});

function save(): void {
    blocked.value = fixturesInvalid.value || actionsInvalid.value;

    if (blocked.value) {
        return;
    }

    if (props.case?.id !== undefined) {
        form.put(BenchmarkCaseController.update.url(props.case.id));

        return;
    }

    form.post(BenchmarkCaseController.store.url());
}

const selectClass =
    'h-9 rounded-md border border-input bg-background px-2 text-sm text-foreground disabled:opacity-60';
const textareaClass =
    'rounded-md border border-input bg-background px-3 py-2 text-[13px] text-foreground';
</script>

<template>
    <Head :title="editing ? 'Edit benchmark case' : 'New benchmark case'" />

    <form class="max-w-[900px] space-y-6 p-6" @submit.prevent="save">
        <h1 class="text-[20px] font-semibold">
            {{ editing ? 'Edit benchmark case' : 'New benchmark case' }}
        </h1>

        <div
            v-if="draftWarnings.length > 0"
            class="grid gap-1.5 rounded-lg border border-warning/30 bg-warning/10 p-3 text-[12.5px] text-warning"
            data-benchmark-draft-warnings
        >
            <p class="flex items-center gap-1.5 font-medium">
                <TriangleAlert class="size-3.5" />Check this draft before saving
            </p>
            <ul class="list-disc pl-5">
                <li
                    v-for="warning in draftWarnings"
                    :key="warning"
                    data-benchmark-draft-warning
                >
                    {{ warning }}
                </li>
            </ul>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="grid gap-1.5 text-[12.5px] font-medium">
                Task
                <select
                    v-model="form.task"
                    :class="selectClass"
                    :disabled="editing"
                    data-benchmark-task
                >
                    <option
                        v-for="task in tasks"
                        :key="task.value"
                        :value="task.value"
                    >
                        {{ task.name }}
                    </option>
                </select>
                <InputError :message="form.errors.task" />
            </label>
            <label class="grid gap-1.5 text-[12.5px] font-medium">
                Name
                <Input v-model="form.name" class="h-9" data-benchmark-name />
                <InputError :message="form.errors.name" />
            </label>
            <label class="grid gap-1.5 text-[12.5px] font-medium">
                Tags
                <Input
                    v-model="tagsText"
                    class="h-9"
                    placeholder="comma, separated"
                    data-benchmark-tags
                />
                <InputError :message="form.errors.tags" />
            </label>
            <label class="grid gap-1.5 text-[12.5px] font-medium">
                Notes
                <Input v-model="form.notes" class="h-9" data-benchmark-notes />
                <InputError :message="form.errors.notes" />
            </label>
        </div>

        <section class="grid gap-3">
            <h2 class="text-[13px] font-semibold">Input</h2>

            <label class="grid gap-1.5 text-[12.5px] font-medium">
                Prompt
                <textarea
                    v-model="form.input.prompt"
                    rows="6"
                    :class="textareaClass"
                    data-benchmark-prompt
                />
                <InputError :message="errors['input.prompt']" />
            </label>

            <div
                v-if="form.task === 'chat'"
                class="grid gap-3"
                data-benchmark-chat-input
            >
                <label
                    class="grid gap-1.5 text-[12.5px] font-medium sm:max-w-xs"
                >
                    Mode
                    <select
                        v-model="form.input.mode"
                        :class="selectClass"
                        data-benchmark-mode
                    >
                        <option value="">Current setting</option>
                        <option value="executive">Executive</option>
                        <option value="advisory">Advisory</option>
                    </select>
                    <InputError :message="errors['input.mode']" />
                </label>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <span class="text-[12.5px] font-medium"
                            >Earlier turns</span
                        >
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="h-7 gap-1 text-xs"
                            data-benchmark-history-add
                            @click="addHistoryTurn"
                        >
                            <Plus class="size-3.5" />Add turn
                        </Button>
                    </div>
                    <div
                        v-for="(turn, index) in form.input.history"
                        :key="index"
                        class="grid gap-2 rounded-lg border border-border bg-card p-3 sm:grid-cols-[120px_1fr_auto]"
                        data-benchmark-history-row
                    >
                        <select
                            v-model="turn.role"
                            :class="selectClass"
                            data-benchmark-history-role
                        >
                            <option value="user">User</option>
                            <option value="assistant">Assistant</option>
                        </select>
                        <div class="grid gap-1">
                            <textarea
                                v-model="turn.content"
                                rows="2"
                                :class="textareaClass"
                                data-benchmark-history-content
                            />
                            <InputError
                                :message="
                                    errors[`input.history.${index}.content`]
                                "
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-8 text-destructive"
                            title="Remove"
                            data-benchmark-history-remove
                            @click="removeHistoryTurn(index)"
                        >
                            <Trash2 class="size-3.5" />
                        </Button>
                    </div>
                </div>
            </div>

            <label
                v-if="form.task === 'decision'"
                class="grid gap-1.5 text-[12.5px] font-medium sm:max-w-md"
            >
                Event
                <select
                    v-model="form.input.event_key"
                    :class="selectClass"
                    data-benchmark-event-key
                >
                    <option value="">Any event</option>
                    <option v-for="key in eventKeys" :key="key" :value="key">
                        {{ key }}
                    </option>
                </select>
                <InputError :message="errors['input.event_key']" />
            </label>

            <fieldset
                v-if="form.task === 'price_updater'"
                class="grid gap-1.5"
                data-benchmark-providers
            >
                <legend class="text-[12.5px] font-medium">Providers</legend>
                <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                    <label
                        v-for="provider in [
                            ...PRICE_PROVIDERS,
                            ...extraProviders,
                        ]"
                        :key="provider"
                        class="flex items-center gap-1.5 text-[13px]"
                    >
                        <input
                            type="checkbox"
                            :checked="form.input.providers.includes(provider)"
                            :data-benchmark-provider="provider"
                            @change="
                                toggleProvider(
                                    provider,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />
                        {{ provider }}
                    </label>
                </div>
                <InputError :message="errors['input.providers']" />
            </fieldset>
        </section>

        <FixtureEditor
            v-model="form.fixtures"
            :errors="errors"
            @update:invalid="fixturesInvalid = $event"
        />

        <section class="grid gap-3" data-benchmark-scoring>
            <h2 class="text-[13px] font-semibold">Scoring</h2>

            <ExpectedActionsEditor
                v-if="scoringType === 'actions'"
                v-model="form.scoring.actions"
                :write-tools="writeTools"
                :errors="errors"
                @update:invalid="actionsInvalid = $event"
            />

            <div
                v-else-if="scoringType === 'prices'"
                class="grid gap-3"
                data-benchmark-prices
            >
                <div class="flex items-end justify-between gap-3">
                    <label class="grid gap-1 text-[12px] text-muted-foreground">
                        Tolerance (%)
                        <Input
                            v-model="form.scoring.tolerance_percent"
                            type="number"
                            min="0"
                            max="50"
                            step="any"
                            class="h-8 w-28 text-sm"
                            data-benchmark-tolerance
                        />
                        <InputError
                            :message="errors['scoring.tolerance_percent']"
                        />
                    </label>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="h-7 gap-1 text-xs"
                        data-benchmark-price-add
                        @click="addPrice"
                    >
                        <Plus class="size-3.5" />Add price
                    </Button>
                </div>
                <div
                    v-for="(price, index) in form.scoring.prices"
                    :key="index"
                    class="grid gap-2 rounded-lg border border-border bg-card p-3 sm:grid-cols-3"
                    data-benchmark-price-row
                >
                    <label class="grid gap-1 text-[12px] text-muted-foreground">
                        Provider
                        <Input v-model="price.provider" class="h-8 text-sm" />
                        <InputError
                            :message="
                                errors[`scoring.expected.${index}.provider`]
                            "
                        />
                    </label>
                    <label class="grid gap-1 text-[12px] text-muted-foreground">
                        Model
                        <Input v-model="price.model" class="h-8 text-sm" />
                        <InputError
                            :message="errors[`scoring.expected.${index}.model`]"
                        />
                    </label>
                    <span class="hidden sm:block" />
                    <label class="grid gap-1 text-[12px] text-muted-foreground">
                        Input $/Mtok
                        <Input
                            v-model="price.input_per_mtok"
                            type="number"
                            min="0"
                            step="any"
                            class="h-8 text-sm"
                        />
                        <InputError
                            :message="
                                errors[
                                    `scoring.expected.${index}.input_per_mtok`
                                ]
                            "
                        />
                    </label>
                    <label class="grid gap-1 text-[12px] text-muted-foreground">
                        Output $/Mtok
                        <Input
                            v-model="price.output_per_mtok"
                            type="number"
                            min="0"
                            step="any"
                            class="h-8 text-sm"
                        />
                        <InputError
                            :message="
                                errors[
                                    `scoring.expected.${index}.output_per_mtok`
                                ]
                            "
                        />
                    </label>
                    <span class="hidden sm:block" />
                    <label class="grid gap-1 text-[12px] text-muted-foreground">
                        Cache read $/Mtok
                        <Input
                            v-model="price.cache_read_per_mtok"
                            type="number"
                            min="0"
                            step="any"
                            class="h-8 text-sm"
                        />
                    </label>
                    <label class="grid gap-1 text-[12px] text-muted-foreground">
                        Cache write $/Mtok
                        <Input
                            v-model="price.cache_write_per_mtok"
                            type="number"
                            min="0"
                            step="any"
                            class="h-8 text-sm"
                        />
                    </label>
                    <div class="flex items-end justify-end">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="h-8 gap-1 text-xs text-destructive"
                            data-benchmark-price-remove
                            @click="removePrice(index)"
                        >
                            <Trash2 class="size-3.5" />Remove
                        </Button>
                    </div>
                </div>
                <p
                    v-if="form.scoring.prices.length === 0"
                    class="rounded-lg border border-dashed border-border p-3 text-[12.5px] text-muted-foreground"
                >
                    No expected prices yet.
                </p>
            </div>

            <div v-else class="grid gap-3" data-benchmark-judge>
                <p
                    v-if="defaultRubrics[form.task]"
                    class="rounded-lg border border-border bg-bg-elev p-3 text-[12.5px] whitespace-pre-line text-muted-foreground"
                    data-benchmark-default-rubric
                >
                    <span class="mb-1 block font-medium text-foreground"
                        >Default rubric</span
                    >{{ defaultRubrics[form.task] }}
                </p>
                <label class="grid gap-1.5 text-[12.5px] font-medium">
                    Extra rubric note
                    <textarea
                        v-model="form.scoring.rubric_note"
                        rows="3"
                        :class="textareaClass"
                        data-benchmark-rubric-note
                    />
                    <InputError :message="errors['scoring.rubric_note']" />
                </label>
                <label
                    class="grid gap-1.5 text-[12.5px] font-medium sm:max-w-[160px]"
                >
                    Pass threshold (1-10)
                    <Input
                        v-model="form.scoring.pass_threshold"
                        type="number"
                        min="1"
                        max="10"
                        class="h-9"
                        data-benchmark-pass-threshold
                    />
                    <InputError :message="errors['scoring.pass_threshold']" />
                </label>
            </div>
            <InputError :message="errors['scoring.type']" />
        </section>

        <p
            v-if="blocked"
            class="text-sm text-destructive"
            data-benchmark-blocked
        >
            Fix the invalid JSON above before saving.
        </p>

        <div class="flex gap-2">
            <Button
                type="submit"
                :disabled="form.processing"
                data-benchmark-save
                >{{ editing ? 'Save changes' : 'Create case' }}</Button
            >
            <Button variant="ghost" as-child>
                <Link :href="BenchmarkCaseController.index.url()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
