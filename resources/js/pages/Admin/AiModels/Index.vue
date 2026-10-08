<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import AiModelsController from '@/actions/App/Http/Controllers/Admin/AiModelsController';
import ModelSelect from '@/components/ai/ModelSelect.vue';
import ReasoningSelect from '@/components/ai/ReasoningSelect.vue';
import UnpricedModelWarning from '@/components/ai/UnpricedModelWarning.vue';
import InputError from '@/components/InputError.vue';
import { Field, Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { dashboard } from '@/routes';
import type { AiReasoningLevel, AiTask } from '@/typefinder';

type ResolvedSummary = {
    provider: string;
    model: string;
    reasoning: AiReasoningLevel;
    reasoning_label: string;
    reasoning_hint: string | null;
};

type TaskRow = {
    task: AiTask;
    label: string;
    inherits_chat: boolean;
    has_reasoning: boolean;
    allow_auto: boolean;
    provider: string | null;
    model: string | null;
    reasoning: AiReasoningLevel | null;
    resolved: ResolvedSummary;
};

type EventOverride = {
    event_key: string;
    enabled: boolean;
    provider: string | null;
    model: string | null;
    reasoning: AiReasoningLevel | null;
    resolved: ResolvedSummary;
};

type Selection = {
    provider: string | null;
    model: string | null;
    reasoning: AiReasoningLevel | null;
};

type EventOverrideSelection = Selection & { event_key: string };

const props = defineProps<{
    tasks: TaskRow[];
    failover: { provider: string | null; model: string | null };
    eventOverrides: EventOverride[];
    allowlistedEvents: string[];
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
    unpricedModels: Array<{ role: string; provider: string; model: string }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'AI Models', href: AiModelsController.index.url() },
        ],
    },
});

const form = useForm({
    tasks: Object.fromEntries(
        props.tasks.map((row) => [
            row.task,
            {
                provider: row.provider,
                model: row.model,
                reasoning: row.reasoning,
            } satisfies Selection,
        ]),
    ) as Record<string, Selection>,
    failover: { ...props.failover },
    event_overrides: props.eventOverrides.map(
        (row): EventOverrideSelection => ({
            event_key: row.event_key,
            provider: row.provider,
            model: row.model,
            reasoning: row.reasoning,
        }),
    ),
});

const resolvedByEvent = computed<Record<string, ResolvedSummary>>(() =>
    Object.fromEntries(
        props.eventOverrides.map((row) => [row.event_key, row.resolved]),
    ),
);

const addableEvents = computed<string[]>(() =>
    props.allowlistedEvents.filter(
        (key) => !form.event_overrides.some((row) => row.event_key === key),
    ),
);

/**
 * ModelSelect speaks '' for "inherit"; the form stores null.
 */
function setProvider(
    selection: { provider: string | null },
    value: string,
): void {
    selection.provider = value === '' ? null : value;
}

function setModel(selection: { model: string | null }, value: string): void {
    selection.model = value === '' ? null : value;
}

function modelSelectInheritLabel(row: TaskRow): string | undefined {
    if (row.inherits_chat) {
        return 'Same as chat';
    }

    return row.task === 'chat' ? undefined : 'Default';
}

/**
 * The provider and model a selection will run on: the edited selection when
 * it names a model, else the server's resolved summary.
 */
function effectivePair(
    selection: Selection,
    resolved: ResolvedSummary,
): { provider: string | null; model: string } {
    return selection.model
        ? { provider: selection.provider, model: selection.model }
        : { provider: resolved.provider, model: resolved.model };
}

/**
 * Why reasoning can't be set for the model this selection will run on, or
 * null when it can.
 */
function reasoningHint(
    selection: Selection,
    resolved: ResolvedSummary,
): string | null {
    const { provider, model } = effectivePair(selection, resolved);

    if (provider === null || !props.reasoningProviders.includes(provider)) {
        return 'Not supported by this provider';
    }

    if (
        props.modelCapabilities[`${provider}|${model}`]?.supports_reasoning ===
        false
    ) {
        return "Model doesn't reason";
    }

    return null;
}

function acceptedLevels(
    selection: Selection,
    resolved: ResolvedSummary,
): AiReasoningLevel[] | null {
    const { provider, model } = effectivePair(selection, resolved);

    return props.modelCapabilities[`${provider}|${model}`]?.levels ?? null;
}

/**
 * A saved override's own resolved summary; a newly added one resolves like
 * the decision task until it is saved.
 */
function overrideResolved(
    eventKey: string,
    decisionRow: TaskRow,
): ResolvedSummary {
    return resolvedByEvent.value[eventKey] ?? decisionRow.resolved;
}

function addOverride(eventKey: string): void {
    form.event_overrides.push({
        event_key: eventKey,
        provider: null,
        model: null,
        reasoning: null,
    });
}

function removeOverride(eventKey: string): void {
    form.event_overrides = form.event_overrides.filter(
        (row) => row.event_key !== eventKey,
    );
}

function setFailoverProvider(value: unknown): void {
    form.failover.provider = value === 'none' ? null : String(value);
    form.failover.model = null;
}

function errorFor(...keys: string[]): string | undefined {
    const errors = form.errors as Record<string, string | undefined>;

    return keys.map((key) => errors[key]).find(Boolean);
}

function submit(): void {
    form.put(AiModelsController.update.url(), { preserveScroll: true });
}
</script>

<template>
    <Head title="AI Models" />

    <div class="flex max-w-3xl flex-col gap-4 p-5">
        <!-- Hero -->
        <div>
            <div class="mb-1.5 text-[13px] text-muted-foreground">
                Admin <span class="text-fg-subtle">/</span> AI models
            </div>
            <h1 class="text-[22px] leading-tight font-semibold tracking-tight">
                AI models
            </h1>
            <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                The model and reasoning level each AI task runs on. Empty fields
                inherit; the line under each task shows what it resolves to.
                Conversations and chat templates can override the chat model.
            </p>
        </div>

        <UnpricedModelWarning :models="unpricedModels" />

        <form
            class="flex flex-col gap-5 rounded-xl border border-border bg-card p-6"
            @submit.prevent="submit"
        >
            <template v-for="row in tasks" :key="row.task">
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                    :data-task="row.task"
                >
                    <Field :label="row.label" />
                    <div class="flex min-w-0 flex-col gap-2">
                        <div data-model-select>
                            <ModelSelect
                                :models="models"
                                :allow-auto="row.allow_auto"
                                :inherit-label="modelSelectInheritLabel(row)"
                                :provider="form.tasks[row.task].provider ?? ''"
                                :model="form.tasks[row.task].model ?? ''"
                                @update:provider="
                                    (value: string) =>
                                        setProvider(form.tasks[row.task], value)
                                "
                                @update:model="
                                    (value: string) =>
                                        setModel(form.tasks[row.task], value)
                                "
                            />
                        </div>
                        <ReasoningSelect
                            v-if="row.has_reasoning"
                            v-model="form.tasks[row.task].reasoning"
                            :levels="reasoningLevels"
                            inherit-label="Default"
                            :accepted="
                                acceptedLevels(
                                    form.tasks[row.task],
                                    row.resolved,
                                )
                            "
                            :disabled-hint="
                                reasoningHint(
                                    form.tasks[row.task],
                                    row.resolved,
                                )
                            "
                        />
                        <p
                            class="font-mono-tabular text-[12px] text-muted-foreground"
                            data-resolved
                        >
                            → {{ row.resolved.provider }} ·
                            {{ row.resolved.model }} ·
                            {{ row.resolved.reasoning_label }}
                        </p>
                        <InputError
                            :message="
                                errorFor(
                                    `tasks.${row.task}.model`,
                                    `tasks.${row.task}.provider`,
                                    `tasks.${row.task}.reasoning`,
                                )
                            "
                        />

                        <!-- Decision event overrides -->
                        <div
                            v-if="row.task === 'decision'"
                            class="mt-2 flex flex-col gap-3 rounded-lg border border-border p-3"
                        >
                            <div
                                class="text-[12px] font-semibold text-muted-foreground"
                            >
                                Event overrides
                            </div>
                            <div
                                v-for="(
                                    override, index
                                ) in form.event_overrides"
                                :key="override.event_key"
                                class="flex flex-col gap-2"
                                :data-event-override="override.event_key"
                            >
                                <div class="flex items-center gap-2">
                                    <span class="font-mono-tabular text-[13px]">
                                        {{ override.event_key }}
                                    </span>
                                    <Pill
                                        v-if="
                                            !allowlistedEvents.includes(
                                                override.event_key,
                                            )
                                        "
                                        variant="warn"
                                    >
                                        Event not enabled
                                    </Pill>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        class="ml-auto"
                                        :aria-label="`Remove the ${override.event_key} override`"
                                        data-remove-override
                                        @click="
                                            removeOverride(override.event_key)
                                        "
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </div>
                                <div data-model-select>
                                    <ModelSelect
                                        :models="models"
                                        inherit-label="Same as decision agent"
                                        :provider="override.provider ?? ''"
                                        :model="override.model ?? ''"
                                        @update:provider="
                                            (value: string) =>
                                                setProvider(override, value)
                                        "
                                        @update:model="
                                            (value: string) =>
                                                setModel(override, value)
                                        "
                                    />
                                </div>
                                <ReasoningSelect
                                    v-model="override.reasoning"
                                    :levels="reasoningLevels"
                                    inherit-label="Same as decision agent"
                                    :accepted="
                                        acceptedLevels(
                                            override,
                                            overrideResolved(
                                                override.event_key,
                                                row,
                                            ),
                                        )
                                    "
                                    :disabled-hint="
                                        reasoningHint(
                                            override,
                                            overrideResolved(
                                                override.event_key,
                                                row,
                                            ),
                                        )
                                    "
                                />
                                <InputError
                                    :message="
                                        errorFor(
                                            `event_overrides.${index}.event_key`,
                                            `event_overrides.${index}.model`,
                                            `event_overrides.${index}.provider`,
                                            `event_overrides.${index}.reasoning`,
                                        )
                                    "
                                />
                            </div>
                            <div
                                v-if="addableEvents.length > 0"
                                data-add-event-override
                            >
                                <!-- Held empty so every pick emits and the placeholder returns. -->
                                <Select
                                    :model-value="''"
                                    @update:model-value="
                                        (value) => addOverride(String(value))
                                    "
                                >
                                    <SelectTrigger
                                        class="h-8 max-w-[320px] text-sm"
                                    >
                                        <Plus class="size-4" />
                                        <SelectValue
                                            placeholder="Add an event override"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="eventKey in addableEvents"
                                            :key="eventKey"
                                            :value="eventKey"
                                            :data-event-option="eventKey"
                                        >
                                            {{ eventKey }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <p v-else class="text-[12px] text-muted-foreground">
                                Every enabled event already has an override, or
                                no events are enabled on the Decision Agent
                                page.
                            </p>
                        </div>
                    </div>
                </div>
                <Separator />
            </template>

            <!-- Failover -->
            <div
                class="grid items-start gap-6"
                style="grid-template-columns: 200px 1fr"
                data-task="failover"
            >
                <Field
                    label="Failover"
                    hint="Where a task goes when its provider fails. Uses the failing task's reasoning."
                />
                <div class="flex min-w-0 flex-col gap-2">
                    <Select
                        :model-value="form.failover.provider ?? 'none'"
                        @update:model-value="setFailoverProvider"
                    >
                        <SelectTrigger
                            class="h-8 max-w-[220px] text-sm"
                            data-failover-provider
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">No failover</SelectItem>
                            <SelectItem
                                v-for="provider in providers"
                                :key="provider"
                                :value="provider"
                            >
                                {{ provider }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <div v-if="form.failover.provider" data-model-select>
                        <ModelSelect
                            :models="{
                                [form.failover.provider]:
                                    models[form.failover.provider] ?? [],
                            }"
                            inherit-label="Provider default"
                            :provider="
                                form.failover.model
                                    ? form.failover.provider
                                    : ''
                            "
                            :model="form.failover.model ?? ''"
                            @update:model="
                                (value: string) =>
                                    setModel(form.failover, value)
                            "
                        />
                    </div>
                    <InputError
                        :message="
                            errorFor('failover.provider', 'failover.model')
                        "
                    />
                </div>
            </div>

            <div class="flex justify-end">
                <Button type="submit" :disabled="form.processing">
                    Save models
                </Button>
            </div>
        </form>
    </div>
</template>
