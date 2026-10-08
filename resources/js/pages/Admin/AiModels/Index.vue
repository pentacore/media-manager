<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import AiModelsController from '@/actions/App/Http/Controllers/Admin/AiModelsController';
import ModelSelect from '@/components/ai/ModelSelect.vue';
import TierListEditor from '@/components/ai/TierListEditor.vue';
import type { ModelPool, TierForm } from '@/components/ai/TierListEditor.vue';
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
    tier: { position: number; count: number; reason: string | null } | null;
};

type TaskRow = {
    task: AiTask;
    label: string;
    inherits_chat: boolean;
    has_reasoning: boolean;
    allow_auto: boolean;
    tiers: TierForm[];
    inherited: ResolvedSummary;
    resolved: ResolvedSummary;
};

type EventOverride = {
    event_key: string;
    enabled: boolean;
    tiers: TierForm[];
    inherited: ResolvedSummary;
    resolved: ResolvedSummary;
};

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
    modelPools: Record<string, ModelPool>;
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
            { tiers: row.tiers.map((tier) => ({ ...tier })) },
        ]),
    ) as Record<string, { tiers: TierForm[] }>,
    failover: { ...props.failover },
    event_overrides: props.eventOverrides.map((row) => ({
        event_key: row.event_key,
        tiers: row.tiers.map((tier) => ({ ...tier })),
    })),
});

const resolvedByEvent = computed<Record<string, ResolvedSummary>>(() =>
    Object.fromEntries(
        props.eventOverrides.map((row) => [row.event_key, row.resolved]),
    ),
);

function eventResolved(eventKey: string): ResolvedSummary | undefined {
    return resolvedByEvent.value[eventKey];
}

const inheritedByEvent = computed<Record<string, ResolvedSummary>>(() =>
    Object.fromEntries(
        props.eventOverrides.map((row) => [row.event_key, row.inherited]),
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
 * What an override's inherit tier runs on: a saved override's own summary; a
 * newly added one hands off to the Decision default list until it is saved.
 */
function overrideInherited(
    eventKey: string,
    decisionRow: TaskRow,
): ResolvedSummary {
    return inheritedByEvent.value[eventKey] ?? decisionRow.resolved;
}

function addOverride(eventKey: string): void {
    form.event_overrides.push({
        event_key: eventKey,
        tiers: [
            {
                provider: null,
                model: null,
                reasoning: null,
                min_pool_percent: null,
                min_pool_tokens: null,
            },
        ],
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

const formErrors = computed(
    () => form.errors as Record<string, string | undefined>,
);

function errorFor(...keys: string[]): string | undefined {
    return keys.map((key) => formErrors.value[key]).find(Boolean);
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
                Add tiers to spend free pools first: a tier runs only while its
                model's pool has the share or tokens you set left; the last tier
                always runs.
            </p>
        </div>

        <UnpricedModelWarning :models="unpricedModels" />

        <form
            class="flex flex-col gap-5 rounded-xl border border-border bg-card p-6"
            @submit.prevent="submit"
        >
            <template v-for="row in tasks" :key="row.task">
                <div
                    class="grid items-start gap-3 md:grid-cols-[200px_1fr] md:gap-6"
                    :data-task="row.task"
                >
                    <Field :label="row.label" />
                    <div class="flex min-w-0 flex-col gap-2">
                        <TierListEditor
                            v-model="form.tasks[row.task].tiers"
                            :models="models"
                            :reasoning-levels="reasoningLevels"
                            :model-capabilities="modelCapabilities"
                            :reasoning-providers="reasoningProviders"
                            :model-pools="modelPools"
                            :inherited-provider="row.inherited.provider"
                            :inherited-model="row.inherited.model"
                            :inherit-label="modelSelectInheritLabel(row)"
                            :has-reasoning="row.has_reasoning"
                            :allow-auto="row.allow_auto"
                            :error-prefix="`tasks.${row.task}.tiers`"
                            :errors="formErrors"
                        />
                        <div class="flex flex-wrap items-center gap-2">
                            <p
                                class="font-mono-tabular text-[12px] text-muted-foreground"
                                data-resolved
                            >
                                → {{ row.resolved.provider }} ·
                                {{ row.resolved.model }} ·
                                {{ row.resolved.reasoning_label }}
                            </p>
                            <Pill
                                v-if="row.resolved.tier"
                                :title="row.resolved.tier.reason ?? undefined"
                                data-tier-live
                            >
                                Live: tier {{ row.resolved.tier.position }} of
                                {{ row.resolved.tier.count }}
                            </Pill>
                        </div>

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
                                <TierListEditor
                                    v-model="override.tiers"
                                    :models="models"
                                    :reasoning-levels="reasoningLevels"
                                    :model-capabilities="modelCapabilities"
                                    :reasoning-providers="reasoningProviders"
                                    :model-pools="modelPools"
                                    :inherited-provider="
                                        overrideInherited(
                                            override.event_key,
                                            row,
                                        ).provider
                                    "
                                    :inherited-model="
                                        overrideInherited(
                                            override.event_key,
                                            row,
                                        ).model
                                    "
                                    inherit-label="Same as decision agent"
                                    reasoning-inherit-label="Same as decision agent"
                                    :error-prefix="`event_overrides.${index}.tiers`"
                                    :errors="formErrors"
                                />
                                <div
                                    v-if="eventResolved(override.event_key)"
                                    class="flex flex-wrap items-center gap-2"
                                >
                                    <p
                                        class="font-mono-tabular text-[12px] text-muted-foreground"
                                        data-override-resolved
                                    >
                                        →
                                        {{
                                            eventResolved(override.event_key)
                                                ?.provider
                                        }}
                                        ·
                                        {{
                                            eventResolved(override.event_key)
                                                ?.model
                                        }}
                                        ·
                                        {{
                                            eventResolved(override.event_key)
                                                ?.reasoning_label
                                        }}
                                    </p>
                                    <Pill
                                        v-if="
                                            eventResolved(override.event_key)
                                                ?.tier
                                        "
                                        :title="
                                            eventResolved(override.event_key)
                                                ?.tier?.reason ?? undefined
                                        "
                                        data-tier-live
                                    >
                                        Live: tier
                                        {{
                                            eventResolved(override.event_key)
                                                ?.tier?.position
                                        }}
                                        of
                                        {{
                                            eventResolved(override.event_key)
                                                ?.tier?.count
                                        }}
                                    </Pill>
                                </div>
                                <InputError
                                    :message="
                                        errorFor(
                                            `event_overrides.${index}.event_key`,
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
                class="grid items-start gap-3 md:grid-cols-[200px_1fr] md:gap-6"
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
