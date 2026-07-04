<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AiSettingsController from '@/actions/App/Http/Controllers/Admin/AiSettingsController';
import InputError from '@/components/InputError.vue';
import { Field, Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { dashboard } from '@/routes';
import type { AiReasoningLevel } from '@/typefinder';
import type { SelectOptionGroup } from '@/types';

interface ModeOption {
    value: string;
    label: string;
}

interface FailoverProviderOption {
    value: string;
    label: string;
}

interface AiSettingsState {
    mode: string;
    model: string;
    title_model: string;
    soft_budget_usd: number | null;
    hard_budget_usd: number | null;
    advisor_reasoning_level: AiReasoningLevel;
    failover_provider: string;
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
    models: Record<string, string[]>;
    reasoningLevels: SelectOptionGroup<AiReasoningLevel>;
    failoverProviders: FailoverProviderOption[];
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
const selectedModel = ref(props.settings.model);
const titleModel = ref(props.settings.title_model);
const selectedReasoningLevel = ref(props.settings.advisor_reasoning_level);
const selectedFailoverProvider = ref(props.settings.failover_provider);

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
                Toggle the assistant, choose a model, set the execution mode.
                Overrides
                <span class="font-mono-tabular">.env</span> at runtime.
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

                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Model"
                        hint="Pick a model from the pricing catalog. Add new entries via Admin → AI prices."
                    >
                        <span />
                    </Field>
                    <div>
                        <Select
                            name="model"
                            v-model="selectedModel"
                            :default-value="settings.model"
                        >
                            <SelectTrigger class="h-8 max-w-[320px] text-sm">
                                <SelectValue placeholder="Select a model" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup
                                    v-for="(modelList, provider) in models"
                                    :key="provider"
                                >
                                    <SelectLabel class="capitalize">
                                        {{ provider }}
                                    </SelectLabel>
                                    <SelectItem
                                        v-for="modelId in modelList"
                                        :key="modelId"
                                        :value="modelId"
                                    >
                                        {{ modelId }}
                                    </SelectItem>
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        <InputError :message="errors.model" class="mt-1" />
                    </div>
                </div>

                <!-- Reasoning Level -->
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Reasoning Level"
                        hint="Level of reasoning applied by the AI assistant. Higher levels may result in more accurate decisions but can be more resource-intensive."
                    >
                        <span />
                    </Field>
                    <div>
                        <Select
                            v-model="selectedReasoningLevel"
                            name="advisor_reasoning_level"
                            :default-value="settings.advisor_reasoning_level"
                        >
                            <SelectTrigger class="h-8 max-w-[320px] text-sm">
                                <SelectValue
                                    placeholder="Select a reasoning level"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="reasoningLevel in reasoningLevels"
                                    :key="reasoningLevel.label"
                                    :value="reasoningLevel.value"
                                >
                                    {{ reasoningLevel.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError
                            :message="errors.advisor_reasoning_level"
                            class="mt-1"
                        />
                    </div>
                </div>

                <!-- Failover provider -->
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Failover provider"
                        hint="If the primary provider errors, the request retries on this provider using its default model. Leave as None to disable failover."
                    >
                        <span />
                    </Field>
                    <div>
                        <Select
                            v-model="selectedFailoverProvider"
                            name="failover_provider"
                            :default-value="settings.failover_provider"
                        >
                            <SelectTrigger class="h-8 max-w-[320px] text-sm">
                                <SelectValue
                                    placeholder="Select a failover provider"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="provider in failoverProviders"
                                    :key="provider.value"
                                    :value="provider.value"
                                >
                                    {{ provider.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError
                            :message="errors.failover_provider"
                            class="mt-1"
                        />
                    </div>
                </div>

                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Title model"
                        hint="Cheap model used to auto-summarize the first user message of a new conversation into a short chat title. Runs in the background queue."
                    >
                        <span />
                    </Field>
                    <div>
                        <Input
                            id="title_model"
                            name="title_model"
                            type="text"
                            class="h-8 max-w-[320px] text-sm"
                            v-model="titleModel"
                            placeholder="gpt-5.4-nano"
                        />
                        <p class="mt-1 text-xs text-muted-foreground">
                            auto = provider's cheapest model
                        </p>
                        <InputError
                            :message="errors.title_model"
                            class="mt-1"
                        />
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
