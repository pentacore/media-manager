<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ExternalLink, ShieldCheck } from '@lucide/vue';
import { ref } from 'vue';
import AdminController from '@/actions/App/Http/Controllers/Bazarr/AdminController';
import SubtitleTabs from '@/components/bazarr/SubtitleTabs.vue';
import { Toggle } from '@/components/mm';
import { dashboard } from '@/routes';

interface Settings {
    language_profiles: {
        id: number | string;
        name: string;
        languages: string[];
    }[];
    profile_assignments: { scope: string; profile_id: number | string }[];
    tasks: { id: string; name: string; status: string }[];
    scheduler: { enabled: boolean; interval_hours: number };
    subtitle_tools: {
        automatic_subtitle_synchronization: boolean;
        use_postprocessing: boolean;
    };
    provider_status: {
        name: string;
        status: string;
        throttled_until: string | null;
    }[];
    notifications: { id: number | string; name: string; enabled: boolean }[];
}

const props = defineProps<{
    connections: { id: number; name: string }[];
    selected_connection_id: number | null;
    requires_connection_selection: boolean;
    settings: Settings | null;
    settings_writable: boolean;
    mappings: {
        role: string;
        connection_id: number;
        connection_name: string;
    }[];
    bazarr_external_url: string | null;
    action_rules_url: string;
    notification_setup: {
        automatic_configuration_supported: boolean;
        authenticated_url: string;
        instructions: string;
    } | null;
    automation: {
        enabled: boolean;
        reconciliation_interval_minutes: number;
        grace_hours: { anime: number; tv: number; movie: number };
        probe_spacing_hours: number;
        empty_probe_threshold: number;
        max_cases_per_cycle: number;
        max_probes_per_cycle: number;
        max_advisor_escalations_per_cycle: number;
        advisor_concurrency: number;
        upload_max_kilobytes: number;
        upload_expiry_hours: number;
    };
}>();

const showNotificationSetup = ref(false);

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Media', href: dashboard().url },
            { title: 'Subtitles', href: AdminController.index.url() },
            { title: 'Administration', href: AdminController.index.url() },
        ],
    },
});

const form = useForm({
    connection: props.selected_connection_id,
    settings: {
        scheduler_enabled: props.settings?.scheduler.enabled ?? false,
        scheduler_interval_hours:
            props.settings?.scheduler.interval_hours ?? 24,
        automatic_subtitle_synchronization:
            props.settings?.subtitle_tools.automatic_subtitle_synchronization ??
            false,
        use_postprocessing:
            props.settings?.subtitle_tools.use_postprocessing ?? false,
    },
});

const automationForm = useForm({
    automation: {
        ...props.automation,
        grace_hours: { ...props.automation.grace_hours },
    },
});

type NumericAutomationKey =
    | 'reconciliation_interval_minutes'
    | 'probe_spacing_hours'
    | 'empty_probe_threshold'
    | 'max_cases_per_cycle'
    | 'max_probes_per_cycle'
    | 'max_advisor_escalations_per_cycle'
    | 'advisor_concurrency'
    | 'upload_max_kilobytes'
    | 'upload_expiry_hours';

const numericAutomationFields: {
    key: NumericAutomationKey;
    label: string;
    min: number;
    max: number;
}[] = [
    {
        key: 'reconciliation_interval_minutes',
        label: 'Interval (minutes)',
        min: 5,
        max: 1440,
    },
    {
        key: 'probe_spacing_hours',
        label: 'Probe spacing (hours)',
        min: 1,
        max: 720,
    },
    {
        key: 'empty_probe_threshold',
        label: 'Empty probe threshold',
        min: 2,
        max: 10,
    },
    { key: 'max_cases_per_cycle', label: 'Cases per cycle', min: 1, max: 1000 },
    {
        key: 'max_probes_per_cycle',
        label: 'Probes per cycle',
        min: 1,
        max: 100,
    },
    {
        key: 'max_advisor_escalations_per_cycle',
        label: 'Advisor escalations',
        min: 0,
        max: 25,
    },
    {
        key: 'advisor_concurrency',
        label: 'Advisor concurrency',
        min: 1,
        max: 5,
    },
    {
        key: 'upload_max_kilobytes',
        label: 'Upload max (KB)',
        min: 64,
        max: 10240,
    },
    {
        key: 'upload_expiry_hours',
        label: 'Upload expiry (hours)',
        min: 1,
        max: 168,
    },
];

function save(): void {
    form.put(AdminController.update.url(), {
        preserveScroll: true,
    });
}

function saveAutomation(): void {
    automationForm.put(AdminController.updateAutomation.url(), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="space-y-6 p-4 sm:p-6">
        <Head title="Bazarr administration" />
        <SubtitleTabs
            active="admin"
            :connections="connections"
            :selected-connection-id="selected_connection_id"
        />

        <div
            v-if="requires_connection_selection"
            class="rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
        >
            Select a Bazarr connection to manage its non-secret settings.
        </div>

        <template v-else-if="settings">
            <div class="flex flex-wrap gap-3">
                <a
                    v-if="bazarr_external_url"
                    :href="bazarr_external_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium hover:bg-muted"
                >
                    Open Bazarr to edit credentials
                    <ExternalLink class="size-4" />
                </a>
                <a
                    :href="action_rules_url"
                    class="inline-flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium hover:bg-muted"
                >
                    Configure Action Rules
                    <ShieldCheck class="size-4" />
                </a>
            </div>

            <div
                v-if="!settings_writable"
                class="rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
            >
                This Bazarr version exposes these settings as read-only.
            </div>

            <form
                data-test="bazarr-admin-form"
                class="grid gap-4 lg:grid-cols-2"
                @submit.prevent="save"
            >
                <section
                    class="space-y-4 rounded-xl border border-border bg-card p-5"
                >
                    <div>
                        <h2 class="font-semibold">Scheduler and tools</h2>
                        <p class="text-sm text-muted-foreground">
                            Only non-secret, allowlisted options are editable.
                        </p>
                    </div>
                    <Toggle
                        v-model="form.settings.scheduler_enabled"
                        label="Scheduler enabled"
                        :disabled="!settings_writable"
                    />
                    <label class="block text-sm font-medium">
                        Scheduler interval (hours)
                        <input
                            v-model.number="
                                form.settings.scheduler_interval_hours
                            "
                            data-test="scheduler-interval"
                            type="number"
                            min="1"
                            max="168"
                            :disabled="!settings_writable"
                            class="mt-1 h-9 w-full rounded-md border border-input bg-background px-3"
                        />
                    </label>
                    <Toggle
                        v-model="
                            form.settings.automatic_subtitle_synchronization
                        "
                        label="Automatic subtitle synchronization"
                        :disabled="!settings_writable"
                    />
                    <Toggle
                        v-model="form.settings.use_postprocessing"
                        label="Use post-processing"
                        :disabled="!settings_writable"
                    />
                    <button
                        v-if="settings_writable"
                        data-test="save-bazarr-settings"
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-50"
                    >
                        Save settings
                    </button>
                </section>

                <section
                    class="space-y-3 rounded-xl border border-border bg-card p-5"
                >
                    <h2 class="font-semibold">Connection mappings</h2>
                    <p
                        v-if="mappings.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No Sonarr or Radarr mappings.
                    </p>
                    <div
                        v-for="mapping in mappings"
                        :key="mapping.role"
                        class="flex justify-between rounded-md border border-border p-3 text-sm"
                    >
                        <span class="capitalize">{{ mapping.role }}</span>
                        <span>{{ mapping.connection_name }}</span>
                    </div>
                </section>
            </form>

            <div class="grid gap-4 lg:grid-cols-2">
                <section class="rounded-xl border border-border bg-card p-5">
                    <h2 class="font-semibold">Language profiles</h2>
                    <p class="mb-3 text-sm text-muted-foreground">
                        Read-only reference. MediaManager requirements remain
                        authoritative.
                    </p>
                    <div
                        v-for="profile in settings.language_profiles"
                        :key="profile.id"
                        class="border-t border-border py-3 text-sm first:border-0"
                    >
                        <p class="font-medium">{{ profile.name }}</p>
                        <p class="text-muted-foreground">
                            {{ profile.languages.join(', ') || 'No languages' }}
                        </p>
                    </div>
                </section>

                <section class="rounded-xl border border-border bg-card p-5">
                    <h2 class="font-semibold">Provider status</h2>
                    <div
                        v-for="provider in settings.provider_status"
                        :key="provider.name"
                        class="flex justify-between border-t border-border py-3 text-sm first:border-0"
                    >
                        <span>{{ provider.name }}</span>
                        <span class="text-muted-foreground">{{
                            provider.throttled_until
                                ? `Throttled until ${provider.throttled_until}`
                                : provider.status
                        }}</span>
                    </div>
                </section>

                <section class="rounded-xl border border-border bg-card p-5">
                    <h2 class="font-semibold">Tasks</h2>
                    <div
                        v-for="task in settings.tasks"
                        :key="task.id"
                        class="flex justify-between border-t border-border py-3 text-sm first:border-0"
                    >
                        <span>{{ task.name }}</span>
                        <span class="text-muted-foreground">{{
                            task.status
                        }}</span>
                    </div>
                </section>

                <section class="rounded-xl border border-border bg-card p-5">
                    <h2 class="font-semibold">Notifications</h2>
                    <div
                        v-for="notification in settings.notifications"
                        :key="notification.id"
                        class="flex justify-between border-t border-border py-3 text-sm first:border-0"
                    >
                        <span>{{ notification.name }}</span>
                        <span class="text-muted-foreground">{{
                            notification.enabled ? 'Enabled' : 'Disabled'
                        }}</span>
                    </div>
                    <button
                        v-if="notification_setup"
                        type="button"
                        class="mt-3 text-sm font-medium text-primary hover:underline"
                        data-test="show-bazarr-notification-hint"
                        @click="showNotificationSetup = !showNotificationSetup"
                    >
                        {{
                            showNotificationSetup
                                ? 'Hide MediaManager notification setup'
                                : 'Show MediaManager notification setup'
                        }}
                    </button>
                    <div
                        v-if="notification_setup && showNotificationSetup"
                        class="mt-3 space-y-2 rounded-lg border border-border bg-muted/40 p-3 text-sm"
                    >
                        <p class="font-medium">
                            Authenticated notification URL
                        </p>
                        <p class="text-muted-foreground">
                            {{ notification_setup.instructions }}
                        </p>
                        <input
                            class="w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-xs"
                            data-test="bazarr-notification-url"
                            readonly
                            :value="notification_setup.authenticated_url"
                        />
                    </div>
                </section>
            </div>

            <form
                class="space-y-4 rounded-xl border border-border bg-card p-5"
                data-test="bazarr-automation-form"
                @submit.prevent="saveAutomation"
            >
                <div>
                    <h2 class="font-semibold">Subtitle automation</h2>
                    <p class="text-sm text-muted-foreground">
                        Reconciliation stays disabled until explicitly enabled.
                    </p>
                </div>
                <Toggle
                    v-model="automationForm.automation.enabled"
                    label="Enable proactive reconciliation"
                />
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label
                        v-for="field in numericAutomationFields"
                        :key="field.key"
                        class="text-sm font-medium"
                    >
                        {{ field.label }}
                        <input
                            v-model.number="
                                automationForm.automation[field.key]
                            "
                            :data-test="`automation-${field.key}`"
                            type="number"
                            :min="field.min"
                            :max="field.max"
                            class="mt-1 h-9 w-full rounded-md border border-input bg-background px-3"
                        />
                    </label>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <label
                        v-for="scope in ['anime', 'tv', 'movie'] as const"
                        :key="scope"
                        class="text-sm font-medium capitalize"
                    >
                        {{ scope }} grace (hours)
                        <input
                            v-model.number="
                                automationForm.automation.grace_hours[scope]
                            "
                            type="number"
                            min="1"
                            max="8760"
                            class="mt-1 h-9 w-full rounded-md border border-input bg-background px-3"
                        />
                    </label>
                </div>
                <button
                    data-test="save-bazarr-automation"
                    type="submit"
                    :disabled="automationForm.processing"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-50"
                >
                    Save automation
                </button>
            </form>
        </template>
    </div>
</template>
