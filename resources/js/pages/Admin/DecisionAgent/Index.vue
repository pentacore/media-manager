<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import DecisionAgentSettingsController from '@/actions/App/Http/Controllers/Admin/DecisionAgentSettingsController';
import InputError from '@/components/InputError.vue';
import { Field, Toggle } from '@/components/mm';
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
import { AiReasoningLevel } from '@/typefinder';

interface DecisionAgentState {
    enabled: boolean;
    model: string;
    event_allowlist: string[];
    allow_manual_import: boolean;
    notify_on_suggest: boolean;
    notify_on_act: boolean;
    max_actions_per_run: number;
    reasoning_level: AiReasoningLevel;
}

const props = defineProps<{
    settings: DecisionAgentState;
    models: Record<string, string[]>;
    eventCatalog: Record<string, string[]>;
    reasoningLevels: Record<string, { label: string; value: AiReasoningLevel }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            {
                title: 'Decision Agent',
                href: DecisionAgentSettingsController.index.url(),
            },
        ],
    },
});

const form = useForm<DecisionAgentState>({
    enabled: props.settings.enabled,
    model: props.settings.model,
    event_allowlist: [...props.settings.event_allowlist],
    allow_manual_import: props.settings.allow_manual_import,
    notify_on_suggest: props.settings.notify_on_suggest,
    notify_on_act: props.settings.notify_on_act,
    max_actions_per_run: props.settings.max_actions_per_run,
    reasoning_level: props.settings.reasoning_level,
});

function eventKey(service: string, event: string): string {
    return `${service}:${event}`;
}

function isAllowed(service: string, event: string): boolean {
    return form.event_allowlist.includes(eventKey(service, event));
}

function toggleEvent(service: string, event: string, on: boolean): void {
    const key = eventKey(service, event);
    const next = form.event_allowlist.filter((k) => k !== key);

    if (on) {
        next.push(key);
    }

    form.event_allowlist = next;
}

function submit(): void {
    form.put(DecisionAgentSettingsController.update.url(), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Decision Agent" />

    <div class="flex max-w-3xl flex-col gap-4 p-5">
        <!-- Hero -->
        <div>
            <div class="mb-1.5 text-[13px] text-muted-foreground">
                Admin <span class="text-fg-subtle">/</span> Decision agent
            </div>
            <h1 class="text-[22px] leading-tight font-semibold tracking-tight">
                Decision agent
            </h1>
            <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                The autonomous agent reacts to inbound webhook events and
                proposes actions through the approval pipeline. Suggest-vs-act
                is governed per-action by
                <span class="font-mono-tabular">Approval Rules</span>; this page
                controls which events wake it and what it may attempt.
            </p>
        </div>

        <form
            class="rounded-xl border border-border bg-card p-6"
            @submit.prevent="submit"
        >
            <div class="flex flex-col gap-5">
                <!-- Enabled -->
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Enabled"
                        hint="Master switch. When off, no inbound event is ever handed to the agent."
                    >
                        <span />
                    </Field>
                    <div>
                        <Toggle
                            :model-value="form.enabled"
                            :label="form.enabled ? 'on' : 'off'"
                            @update:model-value="(v) => (form.enabled = v)"
                        />
                        <InputError
                            :message="form.errors.enabled"
                            class="mt-1"
                        />
                    </div>
                </div>

                <Separator />

                <!-- Model -->
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Model"
                        hint="Model used for background triage. Defaults to the chat model; pick a cheaper one here if you like. Manage entries via Admin → AI prices."
                    >
                        <span />
                    </Field>
                    <div>
                        <Select v-model="form.model">
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
                        <InputError :message="form.errors.model" class="mt-1" />
                    </div>
                </div>

                <!-- Reasoning Level -->
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Reasoning Level"
                        hint="Level of reasoning applied by the decision agent. Higher levels may result in more accurate decisions but can be more resource-intensive."
                    >
                        <span />
                    </Field>
                    <div>
                        <Select v-model="form.reasoning_level">
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
                        <InputError :message="form.errors.model" class="mt-1" />
                    </div>
                </div>

                <Separator />

                <!-- Event allowlist -->
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Event allowlist"
                        hint="Only these inbound events wake the agent. Empty = the agent never runs. Opt in deliberately."
                    >
                        <span />
                    </Field>
                    <div class="flex flex-col gap-4">
                        <div
                            v-for="(events, service) in eventCatalog"
                            :key="service"
                        >
                            <div
                                class="mb-1.5 text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                {{ service }}
                            </div>
                            <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                                <label
                                    v-for="event in events"
                                    :key="event"
                                    class="flex cursor-pointer items-center gap-1.5 text-[12.5px]"
                                >
                                    <input
                                        type="checkbox"
                                        class="size-3.5 accent-accent"
                                        :checked="isAllowed(service, event)"
                                        @change="
                                            (e) =>
                                                toggleEvent(
                                                    service,
                                                    event,
                                                    (
                                                        e.target as HTMLInputElement
                                                    ).checked,
                                                )
                                        "
                                    />
                                    <span class="font-mono-tabular">{{
                                        event
                                    }}</span>
                                </label>
                            </div>
                        </div>
                        <InputError
                            :message="form.errors.event_allowlist"
                            class="mt-1"
                        />
                    </div>
                </div>

                <Separator />

                <!-- Manual import capability -->
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Manual-import resolution"
                        hint="Lets the agent resolve stuck Sonarr/Radarr imports. Ambiguous imports are always queued for approval regardless of the action rule."
                    >
                        <span />
                    </Field>
                    <div>
                        <Toggle
                            :model-value="form.allow_manual_import"
                            :label="
                                form.allow_manual_import ? 'allowed' : 'off'
                            "
                            @update:model-value="
                                (v) => (form.allow_manual_import = v)
                            "
                        />
                        <InputError
                            :message="form.errors.allow_manual_import"
                            class="mt-1"
                        />
                    </div>
                </div>

                <Separator />

                <!-- Notifications -->
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Notify admins"
                        hint="Send an admin notification when the agent suggests and/or auto-acts on something."
                    >
                        <span />
                    </Field>
                    <div class="flex flex-col gap-2.5">
                        <Toggle
                            :model-value="form.notify_on_suggest"
                            :label="
                                form.notify_on_suggest
                                    ? 'on suggest'
                                    : 'suggest: off'
                            "
                            @update:model-value="
                                (v) => (form.notify_on_suggest = v)
                            "
                        />
                        <Toggle
                            :model-value="form.notify_on_act"
                            :label="form.notify_on_act ? 'on act' : 'act: off'"
                            @update:model-value="
                                (v) => (form.notify_on_act = v)
                            "
                        />
                    </div>
                </div>

                <Separator />

                <!-- Per-run cap -->
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Max actions per run"
                        hint="Upper bound on how many actions one decision can spawn — bounds the blast radius of a single event."
                    >
                        <span />
                    </Field>
                    <div>
                        <Input
                            v-model.number="form.max_actions_per_run"
                            type="number"
                            min="1"
                            max="20"
                            class="h-8 max-w-[120px] text-sm"
                        />
                        <InputError
                            :message="form.errors.max_actions_per_run"
                            class="mt-1"
                        />
                    </div>
                </div>

                <div class="flex justify-end pt-2">
                    <Button
                        size="sm"
                        type="submit"
                        :disabled="form.processing"
                        class="h-8 text-xs"
                    >
                        Save settings
                    </Button>
                </div>
            </div>
        </form>
    </div>
</template>
