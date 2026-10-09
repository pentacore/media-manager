<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AiModelsController from '@/actions/App/Http/Controllers/Admin/AiModelsController';
import AiSettingsController from '@/actions/App/Http/Controllers/Admin/AiSettingsController';
import InputError from '@/components/InputError.vue';
import { Field, Pill, Toggle } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { dashboard } from '@/routes';

interface ModeOption {
    value: string;
    label: string;
}

interface AiSettingsState {
    mode: string;
    soft_budget_usd: number | null;
    hard_budget_usd: number | null;
    chat_timeout: number;
    models_dev_pricing_enabled: boolean;
    openrouter_pricing_enabled: boolean;
    litellm_pricing_enabled: boolean;
    xai_pricing_enabled: boolean;
    xai_pricing_key_configured: boolean;
    rate_limits_enforced: boolean;
    ignored_pricing_providers: string[];
    auto_create_pricing_providers: string[];
    classification_provider: string;
    classification_model: string | null;
    decision_gate_enabled: boolean;
    decision_gate_threshold: number;
    subtitle_triage_enabled: boolean;
    subtitle_triage_threshold: number;
    chat_routing_enabled: boolean;
    stuck_import_fast_path_enabled: boolean;
    stuck_import_threshold: number;
    decision_tool_scoping_enabled: boolean;
    classification_audit_sample_rate: number;
    reranking_provider: string;
    reranking_model: string | null;
    embeddings_provider: string;
    embeddings_model: string | null;
    openrouter: {
        sort: string | null;
        deny_data_collection: boolean;
        allow_fallbacks: boolean;
        order: string;
        ignore: string;
    };
}

interface ProviderOption {
    value: string;
    label: string;
}

interface AdvancedTools {
    tool_search: boolean;
    code_execution: boolean;
}

interface PricingProviderOption {
    value: string;
    label: string;
}

interface BudgetSnapshot {
    spend: number;
    soft: number | null;
    hard: number | null;
    soft_notified_at: string | null;
}

const props = defineProps<{
    settings: AiSettingsState;
    budget: BudgetSnapshot;
    modes: ModeOption[];
    pricingProviders: PricingProviderOption[];
    classificationProviders: ProviderOption[];
    rerankingProviders: ProviderOption[];
    providerKeys: Record<string, boolean>;
    advancedTools: AdvancedTools;
    openRouterSorts: ProviderOption[];
    embeddings: {
        stale: boolean;
        indexed_with: string;
        signature: string;
        reembedding: boolean;
    };
    embeddingsProviders: ProviderOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'AI Settings', href: AiSettingsController.index.url() },
        ],
    },
});

const selectedMode = ref(props.settings.mode);
const modelsDevPricingEnabled = ref(props.settings.models_dev_pricing_enabled);
const openRouterPricingEnabled = ref(props.settings.openrouter_pricing_enabled);
const liteLlmPricingEnabled = ref(props.settings.litellm_pricing_enabled);
const xaiPricingEnabled = ref(props.settings.xai_pricing_enabled);
const rateLimitsEnforced = ref(props.settings.rate_limits_enforced);
const ignoredPricingProviders = ref<string[]>([
    ...props.settings.ignored_pricing_providers,
]);
const autoCreatePricingProviders = ref<string[]>([
    ...props.settings.auto_create_pricing_providers,
]);
const selectedClassificationProvider = ref(
    props.settings.classification_provider,
);
const classificationModel = ref(props.settings.classification_model ?? '');
const decisionGateEnabled = ref(props.settings.decision_gate_enabled);
const subtitleTriageEnabled = ref(props.settings.subtitle_triage_enabled);
const chatRoutingEnabled = ref(props.settings.chat_routing_enabled);
const decisionToolScopingEnabled = ref(
    props.settings.decision_tool_scoping_enabled,
);
const stuckImportFastPathEnabled = ref(
    props.settings.stuck_import_fast_path_enabled,
);
const selectedRerankingProvider = ref(props.settings.reranking_provider);
const rerankingModel = ref(props.settings.reranking_model ?? '');
const selectedEmbeddingsProvider = ref(props.settings.embeddings_provider);

function reembedLibrary(): void {
    router.post(
        AiSettingsController.reembed.url(),
        {},
        { preserveScroll: true },
    );
}

const openRouterSort = ref(props.settings.openrouter.sort ?? 'default');
const openRouterDenyDataCollection = ref(
    props.settings.openrouter.deny_data_collection,
);
const openRouterAllowFallbacks = ref(props.settings.openrouter.allow_fallbacks);

function formatUsd(value: number | null): string {
    if (value === null) {
        return '—';
    }

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);
}

const budgetState = computed<{
    label: string;
    variant: 'ok' | 'warn' | 'danger' | 'default';
}>(() => {
    if (props.budget.hard !== null && props.budget.spend >= props.budget.hard) {
        return {
            label: 'Hard cap reached — AI requests blocked',
            variant: 'danger',
        };
    }

    if (props.budget.soft !== null && props.budget.spend >= props.budget.soft) {
        return { label: 'Soft cap reached — admins notified', variant: 'warn' };
    }

    if (props.budget.soft === null && props.budget.hard === null) {
        return { label: 'No budget caps configured', variant: 'default' };
    }

    return { label: 'Within caps', variant: 'ok' };
});
</script>

<template>
    <Head title="AI Settings" />

    <div class="flex max-w-3xl flex-col gap-4 p-5">
        <!-- Hero -->
        <div>
            <div class="mb-1.5 text-[13px] text-muted-foreground">
                Admin <span class="text-fg-subtle">/</span> AI settings
            </div>
            <h1 class="text-[22px] leading-tight font-semibold tracking-tight">
                AI settings
            </h1>
            <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                Toggle the assistant and set the execution mode. Overrides
                <span class="font-mono-tabular">.env</span> at runtime.
            </p>
            <p class="mt-2 text-[13px]">
                Models and reasoning levels are configured on
                <Link
                    :href="AiModelsController.index.url()"
                    class="underline underline-offset-2"
                    data-ai-models-link
                >
                    AI Models →
                </Link>
            </p>
        </div>

        <Form
            v-bind="AiSettingsController.update.form()"
            v-slot="{ errors, processing }"
            class="rounded-xl border border-border bg-card p-6"
        >
            <div class="flex flex-col gap-5">
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Mode"
                        hint="Executive routes destructive tool calls through the approval pipeline. Advisory short-circuits them."
                    >
                        <span />
                    </Field>
                    <div>
                        <Select
                            name="mode"
                            v-model="selectedMode"
                            :default-value="settings.mode"
                        >
                            <SelectTrigger class="h-8 w-48 text-sm">
                                <SelectValue placeholder="Select a mode" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="mode in modes"
                                    :key="mode.value"
                                    :value="mode.value"
                                >
                                    {{ mode.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="errors.mode" class="mt-1" />
                    </div>
                </div>

                <Separator />

                <!-- Chat timeout -->
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Chat timeout"
                        hint="How long a chat turn may wait on the provider before it is aborted. A turn that chains several tool calls needs more time than a plain answer. Raising this past the server request limit (OCTANE_MAX_EXECUTION_TIME) has no effect. Leave blank to use the .env default."
                    >
                        <span />
                    </Field>
                    <div>
                        <div class="relative max-w-[200px]">
                            <Input
                                id="chat_timeout"
                                name="chat_timeout"
                                type="number"
                                step="1"
                                min="30"
                                max="600"
                                class="h-8 pr-10 text-sm"
                                :default-value="settings.chat_timeout"
                                placeholder="120"
                            />
                            <span
                                class="font-mono-tabular pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 text-[12px] text-muted-foreground"
                            >
                                sec
                            </span>
                        </div>
                        <InputError
                            :message="errors.chat_timeout"
                            class="mt-1"
                        />
                    </div>
                </div>

                <Separator />

                <!-- OpenRouter routing -->
                <div class="flex flex-col gap-5" data-openrouter-settings>
                    <div>
                        <h2
                            class="text-[15px] leading-tight font-semibold tracking-tight"
                        >
                            OpenRouter routing
                        </h2>
                        <p
                            class="mt-0.5 max-w-[560px] text-[12px] text-muted-foreground"
                        >
                            Applied to every request sent through OpenRouter.
                            Upstreams are OpenRouter provider slugs such as
                            <span class="font-mono-tabular">anthropic</span> or
                            <span class="font-mono-tabular">amazon-bedrock</span
                            >.
                        </p>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Sort upstreams"
                            hint="Leave on OpenRouter default for load-balanced routing."
                        >
                            <span />
                        </Field>
                        <div>
                            <Select
                                name="openrouter_sort"
                                v-model="openRouterSort"
                            >
                                <SelectTrigger class="h-8 w-56 text-sm">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="default">
                                        OpenRouter default
                                    </SelectItem>
                                    <SelectItem
                                        v-for="sort in openRouterSorts"
                                        :key="sort.value"
                                        :value="sort.value"
                                    >
                                        {{ sort.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError
                                :message="errors.openrouter_sort"
                                class="mt-1"
                            />
                        </div>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Data collection"
                            hint="Only use upstreams that neither store nor train on prompts."
                        >
                            <span />
                        </Field>
                        <div>
                            <Toggle
                                v-model="openRouterDenyDataCollection"
                                data-openrouter-deny-data-collection
                                :label="
                                    openRouterDenyDataCollection
                                        ? 'Denied'
                                        : 'Allowed'
                                "
                            />
                            <input
                                type="hidden"
                                name="openrouter_deny_data_collection"
                                :value="
                                    openRouterDenyDataCollection ? '1' : '0'
                                "
                            />
                        </div>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Allow fallbacks"
                            hint="Let OpenRouter use other upstreams when the preferred ones fail."
                        >
                            <span />
                        </Field>
                        <div>
                            <Toggle
                                v-model="openRouterAllowFallbacks"
                                data-openrouter-allow-fallbacks
                                :label="
                                    openRouterAllowFallbacks
                                        ? 'Allowed'
                                        : 'Disabled'
                                "
                            />
                            <input
                                type="hidden"
                                name="openrouter_allow_fallbacks"
                                :value="openRouterAllowFallbacks ? '1' : '0'"
                            />
                        </div>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Preferred upstreams"
                            hint="Comma-separated, tried in this order."
                        >
                            <span />
                        </Field>
                        <div>
                            <Input
                                id="openrouter_order"
                                name="openrouter_order"
                                type="text"
                                class="h-8 max-w-[420px] text-sm"
                                :default-value="settings.openrouter.order"
                                placeholder="anthropic, amazon-bedrock"
                            />
                            <InputError
                                :message="errors.openrouter_order"
                                class="mt-1"
                            />
                        </div>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Ignored upstreams"
                            hint="Comma-separated upstreams OpenRouter must never use."
                        >
                            <span />
                        </Field>
                        <div>
                            <Input
                                id="openrouter_ignore"
                                name="openrouter_ignore"
                                type="text"
                                class="h-8 max-w-[420px] text-sm"
                                :default-value="settings.openrouter.ignore"
                                placeholder="deepinfra"
                            />
                            <InputError
                                :message="errors.openrouter_ignore"
                                class="mt-1"
                            />
                        </div>
                    </div>
                </div>

                <Separator />

                <!-- Classification -->
                <div class="flex flex-col gap-5" data-classification-settings>
                    <div>
                        <h2
                            class="text-[15px] leading-tight font-semibold tracking-tight"
                        >
                            Classification
                        </h2>
                        <p
                            class="mt-0.5 max-w-[560px] text-[12px] text-muted-foreground"
                        >
                            Cheap classification calls that decide whether a
                            full agent run is worth it. Every gate fails open
                            when classification is unavailable.
                        </p>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Provider"
                            hint="Provider that answers classification calls."
                        >
                            <span />
                        </Field>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <Select
                                    v-model="selectedClassificationProvider"
                                    name="classification_provider"
                                    :default-value="
                                        settings.classification_provider
                                    "
                                >
                                    <SelectTrigger class="h-8 w-48 text-sm">
                                        <SelectValue
                                            placeholder="Select a provider"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="provider in classificationProviders"
                                            :key="provider.value"
                                            :value="provider.value"
                                        >
                                            {{ provider.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <Pill
                                    v-if="
                                        !providerKeys[
                                            selectedClassificationProvider
                                        ]
                                    "
                                    variant="warn"
                                    data-classification-key-missing
                                >
                                    No API key configured
                                </Pill>
                            </div>
                            <InputError
                                :message="errors.classification_provider"
                                class="mt-1"
                            />
                        </div>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Model"
                            hint="Classification model. Leave blank to use the provider's default."
                        >
                            <span />
                        </Field>
                        <div>
                            <Input
                                id="classification_model"
                                name="classification_model"
                                type="text"
                                class="h-8 max-w-[320px] text-sm"
                                v-model="classificationModel"
                                placeholder="Provider default"
                            />
                            <InputError
                                :message="errors.classification_model"
                                class="mt-1"
                            />
                        </div>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Webhook decision gate"
                            hint="Skips the run when classification says the event is unlikely to need action. Stuck-import events always run."
                        >
                            <span />
                        </Field>
                        <div class="flex flex-col gap-2">
                            <div>
                                <Toggle
                                    v-model="decisionGateEnabled"
                                    data-decision-gate-toggle
                                    :label="
                                        decisionGateEnabled
                                            ? 'Enabled'
                                            : 'Disabled'
                                    "
                                />
                                <input
                                    type="hidden"
                                    name="decision_gate_enabled"
                                    :value="decisionGateEnabled ? '1' : '0'"
                                />
                            </div>
                            <label
                                for="decision_gate_threshold"
                                class="flex items-center gap-2 text-[12px] text-muted-foreground"
                            >
                                Threshold
                                <Input
                                    id="decision_gate_threshold"
                                    name="decision_gate_threshold"
                                    type="number"
                                    step="0.05"
                                    min="0"
                                    max="1"
                                    class="h-8 w-24 text-sm"
                                    :default-value="
                                        settings.decision_gate_threshold
                                    "
                                />
                            </label>
                            <InputError
                                :message="
                                    errors.decision_gate_enabled ??
                                    errors.decision_gate_threshold
                                "
                            />
                        </div>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Subtitle escalation triage"
                            hint="Sends cases unlikely to benefit from an automatic replacement straight to review."
                        >
                            <span />
                        </Field>
                        <div class="flex flex-col gap-2">
                            <div>
                                <Toggle
                                    v-model="subtitleTriageEnabled"
                                    data-subtitle-triage-toggle
                                    :label="
                                        subtitleTriageEnabled
                                            ? 'Enabled'
                                            : 'Disabled'
                                    "
                                />
                                <input
                                    type="hidden"
                                    name="subtitle_triage_enabled"
                                    :value="subtitleTriageEnabled ? '1' : '0'"
                                />
                            </div>
                            <label
                                for="subtitle_triage_threshold"
                                class="flex items-center gap-2 text-[12px] text-muted-foreground"
                            >
                                Threshold
                                <Input
                                    id="subtitle_triage_threshold"
                                    name="subtitle_triage_threshold"
                                    type="number"
                                    step="0.05"
                                    min="0"
                                    max="1"
                                    class="h-8 w-24 text-sm"
                                    :default-value="
                                        settings.subtitle_triage_threshold
                                    "
                                />
                            </label>
                            <InputError
                                :message="
                                    errors.subtitle_triage_enabled ??
                                    errors.subtitle_triage_threshold
                                "
                            />
                        </div>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Chat tool routing"
                            hint="Sends MediaAgent only the tools a message needs."
                        >
                            <span />
                        </Field>
                        <div>
                            <Toggle
                                v-model="chatRoutingEnabled"
                                data-chat-routing-toggle
                                :label="
                                    chatRoutingEnabled ? 'Enabled' : 'Disabled'
                                "
                            />
                            <input
                                type="hidden"
                                name="chat_routing_enabled"
                                :value="chatRoutingEnabled ? '1' : '0'"
                            />
                            <InputError
                                :message="errors.chat_routing_enabled"
                                class="mt-1"
                            />
                        </div>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Webhook tool scoping"
                            hint="Also asks which kind of action the event needs, and loads only those tools. Stuck-import events always get every tool."
                        >
                            <span />
                        </Field>
                        <div>
                            <Toggle
                                v-model="decisionToolScopingEnabled"
                                data-decision-tool-scoping-toggle
                                :label="
                                    decisionToolScopingEnabled
                                        ? 'Enabled'
                                        : 'Disabled'
                                "
                            />
                            <input
                                type="hidden"
                                name="decision_tool_scoping_enabled"
                                :value="decisionToolScopingEnabled ? '1' : '0'"
                            />
                            <InputError
                                :message="errors.decision_tool_scoping_enabled"
                                class="mt-1"
                            />
                        </div>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Stuck-import fast path"
                            hint="Lets classification import or remove a stuck download without an agent run when it is confident. Removals still need approval; anything less confident runs the agent."
                        >
                            <span />
                        </Field>
                        <div class="flex flex-col gap-2">
                            <div>
                                <Toggle
                                    v-model="stuckImportFastPathEnabled"
                                    data-stuck-import-toggle
                                    :label="
                                        stuckImportFastPathEnabled
                                            ? 'Enabled'
                                            : 'Disabled'
                                    "
                                />
                                <input
                                    type="hidden"
                                    name="stuck_import_fast_path_enabled"
                                    :value="
                                        stuckImportFastPathEnabled ? '1' : '0'
                                    "
                                />
                            </div>
                            <label
                                for="stuck_import_threshold"
                                class="flex items-center gap-2 text-[12px] text-muted-foreground"
                            >
                                Threshold
                                <Input
                                    id="stuck_import_threshold"
                                    name="stuck_import_threshold"
                                    type="number"
                                    step="0.05"
                                    min="0"
                                    max="1"
                                    class="h-8 w-24 text-sm"
                                    :default-value="
                                        settings.stuck_import_threshold
                                    "
                                />
                            </label>
                            <InputError
                                :message="
                                    errors.stuck_import_fast_path_enabled ??
                                    errors.stuck_import_threshold
                                "
                            />
                        </div>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Audit sample rate"
                            hint="Share of events the webhook gate or subtitle triage would skip that run anyway, to measure how often a skip is wrong. 0 turns audits off."
                        >
                            <span />
                        </Field>
                        <div>
                            <Input
                                id="classification_audit_sample_rate"
                                name="classification_audit_sample_rate"
                                type="number"
                                step="0.01"
                                min="0"
                                max="1"
                                class="h-8 w-24 text-sm"
                                :default-value="
                                    settings.classification_audit_sample_rate
                                "
                            />
                            <InputError
                                :message="
                                    errors.classification_audit_sample_rate
                                "
                                class="mt-1"
                            />
                        </div>
                    </div>
                </div>

                <Separator />

                <!-- Reranking -->
                <div class="flex flex-col gap-5" data-reranking-settings>
                    <div>
                        <h2
                            class="text-[15px] leading-tight font-semibold tracking-tight"
                        >
                            Reranking
                        </h2>
                        <p
                            class="mt-0.5 max-w-[560px] text-[12px] text-muted-foreground"
                        >
                            Reorders semantic library search results by
                            relevance.
                        </p>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Provider"
                            hint="Provider that reranks semantic search results."
                        >
                            <span />
                        </Field>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <Select
                                    v-model="selectedRerankingProvider"
                                    name="reranking_provider"
                                    :default-value="settings.reranking_provider"
                                >
                                    <SelectTrigger
                                        id="reranking_provider"
                                        class="h-8 w-48 text-sm"
                                    >
                                        <SelectValue
                                            placeholder="Select a provider"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="provider in rerankingProviders"
                                            :key="provider.value"
                                            :value="provider.value"
                                            :aria-label="provider.label"
                                        >
                                            {{ provider.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <Pill
                                    v-if="
                                        !providerKeys[selectedRerankingProvider]
                                    "
                                    variant="warn"
                                    data-reranking-key-missing
                                >
                                    No API key configured
                                </Pill>
                            </div>
                            <InputError
                                :message="errors.reranking_provider"
                                class="mt-1"
                            />
                        </div>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Model"
                            hint="Reranking model. Leave blank to use the provider's default."
                        >
                            <span />
                        </Field>
                        <div>
                            <Input
                                id="reranking_model"
                                name="reranking_model"
                                type="text"
                                class="h-8 max-w-[320px] text-sm"
                                v-model="rerankingModel"
                                placeholder="Provider default"
                            />
                            <InputError
                                :message="errors.reranking_model"
                                class="mt-1"
                            />
                        </div>
                    </div>
                </div>

                <Separator />

                <!-- Embeddings -->
                <div class="flex flex-col gap-5" data-embeddings-settings>
                    <div>
                        <h2
                            class="text-[15px] leading-tight font-semibold tracking-tight"
                        >
                            Embeddings
                        </h2>
                        <p
                            class="mt-0.5 max-w-[560px] text-[12px] text-muted-foreground"
                        >
                            Vectors behind semantic library search. Changing the
                            provider or model requires re-embedding the whole
                            library.
                        </p>
                    </div>

                    <div
                        v-if="embeddings.stale"
                        class="flex items-center justify-between gap-4 rounded-md border border-border bg-bg-hover px-3 py-2 text-[13px]"
                        data-embeddings-stale
                    >
                        <span
                            v-if="embeddings.reembedding"
                            class="text-muted-foreground"
                            data-embeddings-reembedding
                        >
                            Re-embedding the library…
                        </span>
                        <span v-else class="text-warning">
                            Library embeddings are stale — built with
                            <span class="font-mono-tabular">{{
                                embeddings.indexed_with
                            }}</span
                            >, search now uses
                            <span class="font-mono-tabular">{{
                                embeddings.signature
                            }}</span
                            >.
                        </span>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            class="h-7 text-xs"
                            data-embeddings-reembed
                            :disabled="embeddings.reembedding"
                            @click="reembedLibrary"
                        >
                            Re-embed library
                        </Button>
                    </div>
                    <div
                        v-else-if="embeddings.reembedding"
                        class="rounded-md border border-border bg-bg-hover px-3 py-2 text-[13px] text-muted-foreground"
                        data-embeddings-reembedding
                    >
                        Re-embedding the library…
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Provider"
                            hint="Provider library and query embeddings are generated on."
                        >
                            <span />
                        </Field>
                        <div>
                            <Select
                                name="embeddings_provider"
                                v-model="selectedEmbeddingsProvider"
                                :default-value="settings.embeddings_provider"
                            >
                                <SelectTrigger class="h-8 w-48 text-sm">
                                    <SelectValue
                                        placeholder="Select a provider"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="provider in embeddingsProviders"
                                        :key="provider.value"
                                        :value="provider.value"
                                    >
                                        {{ provider.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError
                                :message="errors.embeddings_provider"
                                class="mt-1"
                            />
                        </div>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Model"
                            hint="Embeddings model. Leave blank to use the provider's default. Must support 256 dimensions."
                        >
                            <span />
                        </Field>
                        <div>
                            <Input
                                id="embeddings_model"
                                name="embeddings_model"
                                type="text"
                                class="h-8 max-w-[320px] text-sm"
                                :default-value="settings.embeddings_model ?? ''"
                                placeholder="text-embedding-3-small"
                            />
                            <InputError
                                :message="errors.embeddings_model"
                                class="mt-1"
                            />
                        </div>
                    </div>
                </div>

                <Separator />

                <!-- Advanced tools -->
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Advanced tools"
                        hint="Provider-hosted tools register only when every provider the chat (tool search) or price updater (code execution) may reach supports them."
                    >
                        <span />
                    </Field>
                    <div
                        class="flex flex-col gap-1.5 text-[12px] text-muted-foreground"
                        data-advanced-tools
                    >
                        <div data-advanced-tool="tool_search">
                            Hosted tool search:
                            <Pill
                                :variant="
                                    advancedTools.tool_search ? 'ok' : 'default'
                                "
                            >
                                {{
                                    advancedTools.tool_search
                                        ? 'active'
                                        : 'inactive'
                                }}
                            </Pill>
                            — needs every provider in the failover chain to be
                            OpenAI or Anthropic
                        </div>
                        <div data-advanced-tool="code_execution">
                            Code execution (price verifier):
                            <Pill
                                :variant="
                                    advancedTools.code_execution
                                        ? 'ok'
                                        : 'default'
                                "
                            >
                                {{
                                    advancedTools.code_execution
                                        ? 'active'
                                        : 'inactive'
                                }}
                            </Pill>
                            — needs every provider in the chain to support it
                        </div>
                    </div>
                </div>

                <Separator />

                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Soft monthly budget"
                        hint="Triggers a one-shot notification to admins when current-month spend crosses this amount. Leave blank to disable."
                    >
                        <span />
                    </Field>
                    <div>
                        <div class="relative max-w-[200px]">
                            <span
                                class="font-mono-tabular pointer-events-none absolute top-1/2 left-2 -translate-y-1/2 text-[12px] text-muted-foreground"
                            >
                                $
                            </span>
                            <Input
                                id="soft_budget_usd"
                                name="soft_budget_usd"
                                type="number"
                                step="0.01"
                                min="0"
                                class="h-8 pl-5 text-sm"
                                :default-value="settings.soft_budget_usd ?? ''"
                                placeholder="No soft cap"
                            />
                        </div>
                        <InputError
                            :message="errors.soft_budget_usd"
                            class="mt-1"
                        />
                    </div>
                </div>

                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Hard monthly budget"
                        hint="Refuses new AI requests once current-month spend reaches this amount. Resets at the start of each calendar month. Leave blank to disable."
                    >
                        <span />
                    </Field>
                    <div>
                        <div class="relative max-w-[200px]">
                            <span
                                class="font-mono-tabular pointer-events-none absolute top-1/2 left-2 -translate-y-1/2 text-[12px] text-muted-foreground"
                            >
                                $
                            </span>
                            <Input
                                id="hard_budget_usd"
                                name="hard_budget_usd"
                                type="number"
                                step="0.01"
                                min="0"
                                class="h-8 pl-5 text-sm"
                                :default-value="settings.hard_budget_usd ?? ''"
                                placeholder="No hard cap"
                            />
                        </div>
                        <InputError
                            :message="errors.hard_budget_usd"
                            class="mt-1"
                        />
                    </div>
                </div>

                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Model rate limits"
                        hint="When enforced, a model whose per-minute/hour/day limit (set on the AI Prices page) is already used up is refused, and the failover provider takes the turn if one is configured. When informational, limits are only shown on the AI Usage page."
                    >
                        <span />
                    </Field>
                    <div>
                        <Toggle
                            v-model="rateLimitsEnforced"
                            data-rate-limits-toggle
                            :label="
                                rateLimitsEnforced
                                    ? 'Enforced'
                                    : 'Informational'
                            "
                        />
                        <input
                            type="hidden"
                            name="rate_limits_enforced"
                            :value="rateLimitsEnforced ? '1' : '0'"
                        />
                        <InputError
                            :message="errors.rate_limits_enforced"
                            class="mt-1"
                        />
                    </div>
                </div>

                <div
                    class="rounded-md border border-border bg-bg-elev px-3 py-2.5 text-[12px]"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-muted-foreground">
                            Current month spend
                        </span>
                        <span class="font-mono-tabular font-semibold">
                            {{ formatUsd(budget.spend) }}
                        </span>
                    </div>
                    <div
                        v-if="budget.soft !== null"
                        class="mt-1 flex items-center justify-between gap-3"
                    >
                        <span class="text-muted-foreground">Soft cap</span>
                        <span class="font-mono-tabular text-muted-foreground">
                            {{ formatUsd(budget.soft) }}
                        </span>
                    </div>
                    <div
                        v-if="budget.hard !== null"
                        class="mt-1 flex items-center justify-between gap-3"
                    >
                        <span class="text-muted-foreground">Hard cap</span>
                        <span class="font-mono-tabular text-muted-foreground">
                            {{ formatUsd(budget.hard) }}
                        </span>
                    </div>
                    <div class="mt-2 flex items-center justify-end">
                        <Pill
                            :variant="budgetState.variant"
                            :dot="budgetState.variant !== 'default'"
                        >
                            {{ budgetState.label }}
                        </Pill>
                    </div>
                </div>

                <Separator />

                <!-- Pricing sync -->
                <div class="flex flex-col gap-5">
                    <div>
                        <h2
                            class="text-[15px] leading-tight font-semibold tracking-tight"
                        >
                            Pricing sync
                        </h2>
                        <p
                            class="mt-0.5 max-w-[560px] text-[12px] text-muted-foreground"
                        >
                            Controls the automatic model-pricing refresh. These
                            override
                            <span class="font-mono-tabular">.env</span> defaults
                            once saved.
                        </p>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Models.dev feed"
                            hint="Primary price feed for direct providers. When every source is disabled, refreshes fall back to the first-party verifier agent only."
                        >
                            <span />
                        </Field>
                        <div>
                            <Toggle
                                v-model="modelsDevPricingEnabled"
                                data-models-dev-toggle
                                :label="
                                    modelsDevPricingEnabled
                                        ? 'Enabled'
                                        : 'Disabled'
                                "
                            />
                            <input
                                type="hidden"
                                name="models_dev_pricing_enabled"
                                :value="modelsDevPricingEnabled ? '1' : '0'"
                            />
                            <InputError
                                :message="errors.models_dev_pricing_enabled"
                                class="mt-1"
                            />
                        </div>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="LiteLLM cross-check"
                            hint="Cross-checks models.dev against the LiteLLM price map and fills in models models.dev lacks. Agreeing prices are marked as confirmed; conflicts go to the verifier agent."
                        >
                            <span />
                        </Field>
                        <div>
                            <Toggle
                                v-model="liteLlmPricingEnabled"
                                data-litellm-pricing-toggle
                                :label="
                                    liteLlmPricingEnabled
                                        ? 'Enabled'
                                        : 'Disabled'
                                "
                            />
                            <input
                                type="hidden"
                                name="litellm_pricing_enabled"
                                :value="liteLlmPricingEnabled ? '1' : '0'"
                            />
                            <InputError
                                :message="errors.litellm_pricing_enabled"
                                class="mt-1"
                            />
                        </div>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="OpenRouter API"
                            hint="Prices OpenRouter model rows from OpenRouter's public models API."
                        >
                            <span />
                        </Field>
                        <div>
                            <Toggle
                                v-model="openRouterPricingEnabled"
                                data-openrouter-pricing-toggle
                                :label="
                                    openRouterPricingEnabled
                                        ? 'Enabled'
                                        : 'Disabled'
                                "
                            />
                            <input
                                type="hidden"
                                name="openrouter_pricing_enabled"
                                :value="openRouterPricingEnabled ? '1' : '0'"
                            />
                            <InputError
                                :message="errors.openrouter_pricing_enabled"
                                class="mt-1"
                            />
                        </div>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="xAI pricing API"
                            hint="Prices xAI rows from xAI's own pricing API. These prices count as first-party verified."
                        >
                            <span />
                        </Field>
                        <div>
                            <Toggle
                                v-model="xaiPricingEnabled"
                                data-xai-pricing-toggle
                                :label="
                                    xaiPricingEnabled ? 'Enabled' : 'Disabled'
                                "
                            />
                            <input
                                type="hidden"
                                name="xai_pricing_enabled"
                                :value="xaiPricingEnabled ? '1' : '0'"
                            />
                            <p
                                v-if="!settings.xai_pricing_key_configured"
                                data-xai-pricing-key-missing
                                class="mt-1 text-xs text-warning"
                            >
                                No XAI_API_KEY is configured, so this source is
                                skipped and xAI falls back to the feeds.
                            </p>
                            <InputError
                                :message="errors.xai_pricing_enabled"
                                class="mt-1"
                            />
                        </div>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Ignored providers"
                            hint="Providers excluded from every automatic pricing path (feed sync, agent fallback, CLI scope). Their existing rows are kept as last-known-good."
                        >
                            <span />
                        </Field>
                        <div>
                            <div class="flex flex-col gap-2">
                                <label
                                    v-for="provider in pricingProviders"
                                    :key="provider.value"
                                    :for="`ignore-provider-${provider.value}`"
                                    class="flex cursor-pointer items-center gap-2 text-[13px]"
                                >
                                    <input
                                        :id="`ignore-provider-${provider.value}`"
                                        type="checkbox"
                                        :value="provider.value"
                                        v-model="ignoredPricingProviders"
                                        class="size-4 rounded border-border accent-accent"
                                    />
                                    {{ provider.label }}
                                </label>
                            </div>
                            <input
                                v-for="provider in ignoredPricingProviders"
                                :key="provider"
                                type="hidden"
                                name="ignored_pricing_providers[]"
                                :value="provider"
                            />
                            <InputError
                                :message="errors.ignored_pricing_providers"
                                class="mt-1"
                            />
                        </div>
                    </div>

                    <div
                        class="grid items-start gap-6"
                        style="grid-template-columns: 200px 1fr"
                    >
                        <Field
                            label="Add new models"
                            hint="The refresh adds models these providers newly report to the catalog. Unchecked providers are update-only: their existing prices keep refreshing, and only new models the app uses (usage history or picked in these settings) are added."
                        >
                            <span />
                        </Field>
                        <div data-auto-create-providers>
                            <div class="flex flex-col gap-2">
                                <label
                                    v-for="provider in pricingProviders"
                                    :key="provider.value"
                                    :for="`auto-create-provider-${provider.value}`"
                                    class="flex cursor-pointer items-center gap-2 text-[13px]"
                                >
                                    <input
                                        :id="`auto-create-provider-${provider.value}`"
                                        type="checkbox"
                                        :value="provider.value"
                                        v-model="autoCreatePricingProviders"
                                        :disabled="
                                            ignoredPricingProviders.includes(
                                                provider.value,
                                            )
                                        "
                                        class="size-4 rounded border-border accent-accent disabled:cursor-not-allowed disabled:opacity-50"
                                    />
                                    {{ provider.label }}
                                    <span
                                        v-if="
                                            ignoredPricingProviders.includes(
                                                provider.value,
                                            )
                                        "
                                        class="text-[12px] text-muted-foreground"
                                    >
                                        (ignored)
                                    </span>
                                </label>
                            </div>
                            <!-- Blank placeholder so an all-unchecked list still submits as an empty list. -->
                            <input
                                type="hidden"
                                name="auto_create_pricing_providers[]"
                                value=""
                            />
                            <input
                                v-for="provider in autoCreatePricingProviders"
                                :key="provider"
                                type="hidden"
                                name="auto_create_pricing_providers[]"
                                :value="provider"
                            />
                            <InputError
                                :message="errors.auto_create_pricing_providers"
                                class="mt-1"
                            />
                        </div>
                    </div>
                </div>

                <div class="flex justify-end pt-2">
                    <Button
                        size="sm"
                        type="submit"
                        :disabled="processing"
                        class="h-8 text-xs"
                    >
                        Save settings
                    </Button>
                </div>
            </div>
        </Form>
    </div>
</template>
