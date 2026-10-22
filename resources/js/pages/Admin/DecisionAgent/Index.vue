<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AiModelsController from '@/actions/App/Http/Controllers/Admin/AiModelsController';
import DecisionAgentSettingsController from '@/actions/App/Http/Controllers/Admin/DecisionAgentSettingsController';
import InputError from '@/components/InputError.vue';
import { Field, SettingsRow, Toggle } from '@/components/mm';
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
import type { SecondOpinionEngine } from '@/typefinder/enums/SecondOpinionEngine';

interface DecisionAgentState {
    enabled: boolean;
    event_allowlist: string[];
    allow_manual_import: boolean;
    notify_on_suggest: boolean;
    notify_on_act: boolean;
    max_actions_per_run: number;
    second_opinion_engine: SecondOpinionEngine;
    second_opinion_jev_threshold: number;
}

const props = defineProps<{
    settings: DecisionAgentState;
    eventCatalog: Record<string, string[]>;
    secondOpinionEngines: Array<{
        value: SecondOpinionEngine;
        label: string;
    }>;
    classifierConfigured: boolean;
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
    event_allowlist: [...props.settings.event_allowlist],
    allow_manual_import: props.settings.allow_manual_import,
    notify_on_suggest: props.settings.notify_on_suggest,
    notify_on_act: props.settings.notify_on_act,
    max_actions_per_run: props.settings.max_actions_per_run,
    second_opinion_engine: props.settings.second_opinion_engine,
    second_opinion_jev_threshold: props.settings.second_opinion_jev_threshold,
});

const usesClassifier = computed(() => form.second_opinion_engine !== 'llm');

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

        <form
            class="rounded-xl border border-border bg-card p-6"
            @submit.prevent="submit"
        >
            <div class="flex flex-col gap-5">
                <!-- Enabled -->
                <SettingsRow>
                    <template #label>
                        <Field
                            label="Enabled"
                            hint="Master switch. When off, no inbound event is ever handed to the agent."
                        >
                            <span />
                        </Field>
                    </template>
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
                </SettingsRow>

                <Separator />

                <!-- Event allowlist -->
                <SettingsRow>
                    <template #label>
                        <Field
                            label="Event allowlist"
                            hint="Only these inbound events wake the agent. Empty = the agent never runs. Opt in deliberately."
                        >
                            <span />
                        </Field>
                    </template>
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
                </SettingsRow>

                <Separator />

                <!-- Manual import capability -->
                <SettingsRow>
                    <template #label>
                        <Field
                            label="Manual-import resolution"
                            hint="Lets the agent resolve stuck Sonarr/Radarr imports. Ambiguous imports are always queued for approval regardless of the action rule."
                        >
                            <span />
                        </Field>
                    </template>
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
                </SettingsRow>

                <Separator />

                <!-- Notifications -->
                <SettingsRow>
                    <template #label>
                        <Field
                            label="Notify admins"
                            hint="Send an admin notification when the agent suggests and/or auto-acts on something."
                        >
                            <span />
                        </Field>
                    </template>
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
                </SettingsRow>

                <Separator />

                <!-- Per-run cap -->
                <SettingsRow>
                    <template #label>
                        <Field
                            label="Max actions per run"
                            hint="Upper bound on how many actions one decision can spawn — bounds the blast radius of a single event."
                        >
                            <span />
                        </Field>
                    </template>
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
                </SettingsRow>

                <Separator />

                <!-- Second opinion -->
                <SettingsRow>
                    <template #label>
                        <Field
                            label="Second opinion"
                            hint="How automated proposals are double-checked on the action types you choose under Approval Rules. A failed check always holds the action for approval."
                        >
                            <span />
                        </Field>
                    </template>
                    <div class="flex flex-col gap-2.5">
                        <Select v-model="form.second_opinion_engine">
                            <SelectTrigger
                                class="h-8 max-w-[260px] text-sm"
                                data-second-opinion-engine
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="engine in secondOpinionEngines"
                                    :key="engine.value"
                                    :value="engine.value"
                                    :disabled="
                                        engine.value !== 'llm' &&
                                        !classifierConfigured
                                    "
                                    :data-second-opinion-engine-option="
                                        engine.value
                                    "
                                >
                                    {{ engine.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p
                            v-if="!classifierConfigured"
                            class="text-[12px] text-fg-subtle"
                            data-second-opinion-classifier-hint
                        >
                            Configure a classification provider on AI Settings
                            to use Jev.
                        </p>
                        <div
                            v-if="usesClassifier"
                            class="flex items-center gap-2"
                        >
                            <Input
                                v-model.number="
                                    form.second_opinion_jev_threshold
                                "
                                type="number"
                                min="0"
                                max="1"
                                step="0.05"
                                class="h-8 max-w-[120px] text-sm"
                                data-second-opinion-threshold
                            />
                            <span class="text-[12px] text-muted-foreground">
                                Jev agrees at or above this probability.
                            </span>
                        </div>
                        <InputError
                            :message="form.errors.second_opinion_engine"
                        />
                        <InputError
                            :message="form.errors.second_opinion_jev_threshold"
                        />
                    </div>
                </SettingsRow>

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
