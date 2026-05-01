<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { edit, update } from '@/routes/settings/notifications';

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

// mail and ntfy toggles are stored but the dispatch path doesn't honour
// them yet — flag the columns visually so the user knows.
const PENDING_CHANNELS = new Set(['mail', 'ntfy']);

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
            description="Pick which channels each notification reaches you on. Defaults: in-app + live toast on. Email and ntfy are stored but not delivered yet."
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
                                    <span
                                        v-if="PENDING_CHANNELS.has(channel)"
                                        class="ml-1 text-[10px] text-fg-subtle"
                                        >(soon)</span
                                    >
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
                                        :disabled="
                                            PENDING_CHANNELS.has(channel)
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

        <div class="flex justify-end">
            <Button :disabled="saving" @click="save">
                {{ saving ? 'Saving…' : 'Save preferences' }}
            </Button>
        </div>
    </div>
</template>
