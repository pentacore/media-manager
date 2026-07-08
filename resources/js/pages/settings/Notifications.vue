<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    edit,
    test as testRoute,
    update,
} from '@/routes/settings/notifications';

interface SeverityFlags {
    database: boolean;
    broadcast: boolean;
    mail: boolean;
    ntfy: boolean;
}

interface CatalogEntry {
    class: string;
    label: string;
    description: string;
    severities: Record<string, SeverityFlags>;
}

const props = defineProps<{
    catalog: CatalogEntry[];
    channels: string[];
    severities: string[];
    ntfyTopic: string | null;
    ntfyConfigured: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notification preferences', href: edit() }],
    },
});

// Local working copy so toggles feel instant; we PUT on Save.
const working = reactive<CatalogEntry[]>(
    props.catalog.map((entry) => ({
        ...entry,
        severities: Object.fromEntries(
            Object.entries(entry.severities).map(([sev, flags]) => [
                sev,
                { ...flags },
            ]),
        ),
    })),
);

const SEVERITY_LABELS: Record<string, string> = {
    info: 'Info',
    warning: 'Warning',
    error: 'Error',
};

function channelLabel(channel: string): string {
    if (channel === 'database') {
        return 'In-app';
    }

    if (channel === 'broadcast') {
        return 'Toast / live';
    }

    if (channel === 'mail') {
        return 'Email';
    }

    if (channel === 'ntfy') {
        return 'ntfy';
    }

    return channel;
}

const saving = ref(false);
const ntfyTopic = ref(props.ntfyTopic ?? '');
const testing = ref(false);

function sendTest(): void {
    testing.value = true;
    router.post(
        testRoute().url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                testing.value = false;
            },
        },
    );
}

function save(): void {
    saving.value = true;
    router.put(
        update().url,
        {
            preferences: working.map((entry) => ({
                class: entry.class,
                severities: Object.fromEntries(
                    Object.entries(entry.severities).map(([sev, flags]) => [
                        sev,
                        { ...flags },
                    ]),
                ),
            })),
            ntfy_topic: ntfyTopic.value === '' ? null : ntfyTopic.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Notification preferences" />

    <h1 class="sr-only">Notification preferences</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Notification preferences"
            description="Pick which channels each notification reaches you on. Defaults: in-app + live toast on."
        />

        <div class="space-y-6">
            <div
                v-for="entry in working"
                :key="entry.class"
                class="rounded-xl border border-border bg-card"
            >
                <div class="border-b border-border px-4 py-3">
                    <div class="text-[14px] font-semibold">
                        {{ entry.label }}
                    </div>
                    <p class="mt-1 text-[12.5px] text-muted-foreground">
                        {{ entry.description }}
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-[12.5px]">
                        <thead>
                            <tr>
                                <th
                                    class="border-b border-border bg-card px-3 py-2 text-left text-[11px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                                >
                                    Severity
                                </th>
                                <th
                                    v-for="channel in channels"
                                    :key="channel"
                                    class="border-b border-border bg-card px-3 py-2 text-center text-[11px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                                >
                                    {{ channelLabel(channel) }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="severity in severities"
                                :key="severity"
                                class="border-b border-border last:border-b-0"
                            >
                                <td class="px-3 py-2.5 font-medium">
                                    {{ SEVERITY_LABELS[severity] ?? severity }}
                                </td>
                                <td
                                    v-for="channel in channels"
                                    :key="channel"
                                    class="px-3 py-2.5 text-center"
                                >
                                    <input
                                        type="checkbox"
                                        v-model="
                                            entry.severities[severity][
                                                channel as keyof SeverityFlags
                                            ]
                                        "
                                        class="size-4 rounded border-border accent-accent disabled:opacity-40"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div
            v-if="ntfyConfigured"
            class="rounded-xl border border-border bg-card p-4"
        >
            <div class="text-[14px] font-semibold">ntfy topic</div>
            <p class="mt-1 mb-3 text-[12.5px] text-muted-foreground">
                Pushes go to this topic on the configured ntfy server. Leave
                empty to disable ntfy for your account.
            </p>
            <div class="flex items-end gap-2">
                <div class="flex-1 space-y-2">
                    <Label for="ntfy_topic">Topic</Label>
                    <Input
                        id="ntfy_topic"
                        v-model="ntfyTopic"
                        name="ntfy_topic"
                        placeholder="my-mediamanager-alerts"
                    />
                    <InputError :message="$page.props.errors.ntfy_topic" />
                </div>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="!props.ntfyTopic || testing"
                    @click="sendTest"
                >
                    {{ testing ? 'Sending…' : 'Send test' }}
                </Button>
            </div>
            <p
                v-if="ntfyTopic !== (props.ntfyTopic ?? '')"
                class="mt-2 text-[12px] text-muted-foreground"
            >
                Save preferences before sending a test to a changed topic.
            </p>
        </div>

        <div class="flex justify-end">
            <Button :disabled="saving" @click="save">
                {{ saving ? 'Saving…' : 'Save preferences' }}
            </Button>
        </div>
    </div>
</template>
