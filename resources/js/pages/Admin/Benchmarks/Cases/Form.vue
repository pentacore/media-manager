<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BenchmarkCaseController from '@/actions/App/Http/Controllers/Admin/BenchmarkCaseController';
import {
    CaseInputFields,
    ExpectedActionsEditor,
    FixtureEditor,
    JudgeScoringFields,
    PriceExpectationsEditor,
} from '@/components/benchmarks';
import type {
    BenchmarkCasePayload,
    BenchmarkAttachmentEntry,
    BenchmarkExpectedAction,
    BenchmarkExpectedPrice,
    BenchmarkFixture,
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

const SCORING_BY_TASK: Record<string, BenchmarkScoringKind> = {
    decision: 'actions',
    price_updater: 'prices',
};

const source = props.case ?? props.draft;
const editing = props.case !== null;

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

/** Saved attachments of a turn (`null` = the final prompt), then draft copies. */
function turnEntries(turn: number | null): BenchmarkAttachmentEntry[] {
    return [
        ...(props.case?.attachments ?? [])
            .filter((attachment) => attachment.turn === turn)
            .map((attachment): BenchmarkAttachmentEntry => ({
                kind: 'saved',
                id: attachment.id,
                name: attachment.name,
                size: attachment.size,
            })),
        ...(props.draft?.draft_attachments ?? [])
            .filter((attachment) => attachment.turn === turn)
            .map((attachment): BenchmarkAttachmentEntry => ({
                kind: 'draft',
                chatAttachmentId: attachment.chat_attachment_id,
                name: attachment.name,
                size: attachment.size,
            })),
    ];
}

const initialTask = source?.task ?? props.tasks[0]?.value ?? 'chat';

const form = useForm({
    task: initialTask,
    name: source?.name ?? '',
    tags: [...(source?.tags ?? [])],
    notes: source?.notes ?? '',
    input: {
        prompt: source?.input.prompt ?? '',
        history: (source?.input.history ?? []).map((turn, index) => ({
            ...turn,
            attachments: turnEntries(index),
        })),
        mode: source?.input.mode ?? '',
        conversation: source?.input.conversation ?? 'context',
        attachments: turnEntries(null),
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

const fixturesInvalid = ref(false);
const actionsInvalid = ref(false);

const showBlocked = computed(
    () => blocked.value && (fixturesInvalid.value || actionsInvalid.value),
);

watch(scoringType, (type) => {
    form.scoring.type = type;
    // The actions editor unmounts with its section and can no longer clear this.
    actionsInvalid.value = false;
});

const blocked = ref(false);

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

function numberOrNull(
    value: number | string | null | undefined,
): number | null {
    return value === null || value === undefined || value === ''
        ? null
        : Number(value);
}

const tagsError = computed(
    () =>
        Object.entries(errors.value).find(
            ([key, message]) => key.startsWith('tags') && message,
        )?.[1],
);

/** Only the input fields the chosen task reads are sent. */
form.transform((data) => {
    const input: Record<string, unknown> = { prompt: data.input.prompt };

    if (data.task === 'chat') {
        input.history = data.input.history.map(({ role, content }) => ({
            role,
            content,
        }));
        input.mode = data.input.mode === '' ? null : data.input.mode;
        input.conversation = data.input.conversation;
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

    const keep: Record<string, number[]> = {};
    const drafts: Record<string, number[]> = {};
    const files: Record<string, File[]> = {};

    if (data.task === 'chat') {
        const turns: Array<[string, BenchmarkAttachmentEntry[]]> = [
            ...data.input.history.map(
                (turn, index): [string, BenchmarkAttachmentEntry[]] => [
                    String(index),
                    turn.role === 'user' ? turn.attachments : [],
                ],
            ),
            ['final', data.input.attachments],
        ];

        for (const [key, entries] of turns) {
            for (const entry of entries) {
                if (entry.kind === 'saved') {
                    (keep[key] ??= []).push(entry.id);
                } else if (entry.kind === 'draft') {
                    (drafts[key] ??= []).push(entry.chatAttachmentId);
                } else {
                    (files[key] ??= []).push(entry.file);
                }
            }
        }
    }

    const body = {
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
        ...(data.task === 'chat'
            ? { keep_attachment_ids: keep, draft_attachment_ids: drafts }
            : {}),
    };

    return {
        payload: JSON.stringify(body),
        attachments: files,
        ...(editing ? { _method: 'put' } : {}),
    };
});

function save(): void {
    blocked.value = fixturesInvalid.value || actionsInvalid.value;

    if (blocked.value) {
        return;
    }

    form.post(
        props.case?.id !== undefined
            ? BenchmarkCaseController.update.url(props.case.id)
            : BenchmarkCaseController.store.url(),
        { forceFormData: true },
    );
}

const selectClass =
    'h-9 rounded-md border border-input bg-background px-2 text-sm text-foreground disabled:opacity-60';
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
                <InputError :message="tagsError" />
            </label>
            <label class="grid gap-1.5 text-[12.5px] font-medium">
                Notes
                <Input v-model="form.notes" class="h-9" data-benchmark-notes />
                <InputError :message="form.errors.notes" />
            </label>
        </div>

        <CaseInputFields
            v-model="form.input"
            :task="form.task"
            :event-keys="eventKeys"
            :errors="errors"
        />

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

            <PriceExpectationsEditor
                v-else-if="scoringType === 'prices'"
                v-model:prices="form.scoring.prices"
                v-model:tolerance="form.scoring.tolerance_percent"
                :errors="errors"
            />

            <JudgeScoringFields
                v-else
                v-model:rubric-note="form.scoring.rubric_note"
                v-model:pass-threshold="form.scoring.pass_threshold"
                :default-rubric="defaultRubrics[form.task]"
                :errors="errors"
            />
            <InputError :message="errors['scoring.type']" />
        </section>

        <p
            v-if="showBlocked"
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
