<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { FlaskConical } from '@lucide/vue';
import { computed, onMounted } from 'vue';
import AiModelsController from '@/actions/App/Http/Controllers/Admin/AiModelsController';
import ModelSelect from '@/components/ai/ModelSelect.vue';
import TierListEditor from '@/components/ai/TierListEditor.vue';
import type { ModelPool, TierForm } from '@/components/ai/TierListEditor.vue';
import UnpricedModelWarning from '@/components/ai/UnpricedModelWarning.vue';
import { EventOverridesSection } from '@/components/ai-models';
import type {
    EventOverrideForm,
    EventOverrideRow,
    ResolvedSummary,
} from '@/components/ai-models';
import InputError from '@/components/InputError.vue';
import { Field, Pill } from '@/components/mm';
import { Alert, AlertDescription } from '@/components/ui/alert';
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

type TaskRow = {
    task: AiTask;
    label: string;
    inherits_chat: boolean;
    has_reasoning: boolean;
    allow_auto: boolean;
    allow_tiers: boolean;
    accepts_event_scopes: boolean;
    tiers: TierForm[];
    inherited: ResolvedSummary;
    resolved: ResolvedSummary;
    warning: string | null;
};

type BenchmarkPrefill = {
    task: AiTask;
    provider: string;
    model: string;
    reasoning: AiReasoningLevel | null;
    run_id: number;
    run_name: string;
};

const props = defineProps<{
    prefill: BenchmarkPrefill | null;
    tasks: TaskRow[];
    failover: { provider: string | null; model: string | null };
    eventOverrides: EventOverrideRow[];
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
    event_overrides: props.eventOverrides.map((row): EventOverrideForm => ({
        task: row.task,
        event_key: row.event_key,
        tiers: row.tiers.map((tier) => ({ ...tier })),
    })),
});

const prefillLabel = computed<string | null>(
    () =>
        props.tasks.find((row) => row.task === props.prefill?.task)?.label ??
        null,
);

/** Puts a benchmark candidate into tier 1 of its task; nothing is saved until Save. */
function applyPrefill(): void {
    const prefill = props.prefill;
    const row = props.tasks.find((task) => task.task === prefill?.task);

    if (!prefill || !row) {
        return;
    }

    const selection = form.tasks[prefill.task];

    const tier = selection.tiers[0];
    tier.provider = prefill.provider;
    tier.model = prefill.model;

    if (row.has_reasoning) {
        tier.reasoning = prefill.reasoning;
    }
}

applyPrefill();

onMounted(() => {
    if (!props.prefill) {
        return;
    }

    document
        .querySelector(`[data-task="${props.prefill.task}"]`)
        ?.scrollIntoView({ block: 'center' });

    // A reload must not re-apply the prefill: swap the history entry's URL
    // client-side, which also keeps Inertia's page.url in sync.
    router.replace({
        url: AiModelsController.index.url(),
        preserveState: true,
        preserveScroll: true,
    });
});

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

function savedOverrides(task: AiTask): EventOverrideRow[] {
    return props.eventOverrides.filter((row) => row.task === task);
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

/** Row indexes shift on add/remove/reorder, so drop that list's stale errors. */
function clearTierErrors(prefix: string): void {
    const staleKeys = Object.keys(form.errors).filter((key) =>
        key.startsWith(`${prefix}.`),
    ) as Array<keyof typeof form.errors>;

    // clearErrors() with no keys wipes every error on the page.
    if (staleKeys.length === 0) {
        return;
    }

    form.clearErrors(...staleKeys);
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

        <Alert v-if="prefill" data-ai-models-prefill>
            <FlaskConical />
            <AlertDescription>
                Tier 1 of {{ prefillLabel ?? prefill.task }} pre-filled from
                benchmark run {{ prefill.run_name }} — not saved yet.
            </AlertDescription>
        </Alert>

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
                            :label="row.label"
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
                            :allow-tiers="row.allow_tiers"
                            :error-prefix="`tasks.${row.task}.tiers`"
                            :errors="formErrors"
                            @structure-change="
                                clearTierErrors(`tasks.${row.task}.tiers`)
                            "
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
                                Live:
                                <template v-if="row.resolved.tier.via">
                                    via {{ row.resolved.tier.via }} ·
                                </template>
                                tier {{ row.resolved.tier.position }} of
                                {{ row.resolved.tier.count }}
                            </Pill>
                        </div>
                        <p
                            v-if="row.warning"
                            class="text-[12px] text-warning"
                            data-task-warning
                        >
                            {{ row.warning }}
                        </p>

                        <EventOverridesSection
                            v-if="row.accepts_event_scopes"
                            v-model:overrides="form.event_overrides"
                            :task="row.task"
                            :task-label="row.label"
                            :saved="savedOverrides(row.task)"
                            :fallback-inherited="row.resolved"
                            :allowlisted-events="allowlistedEvents"
                            :models="models"
                            :reasoning-levels="reasoningLevels"
                            :model-capabilities="modelCapabilities"
                            :reasoning-providers="reasoningProviders"
                            :model-pools="modelPools"
                            :errors="formErrors"
                            @structure-change="clearTierErrors"
                        />
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
