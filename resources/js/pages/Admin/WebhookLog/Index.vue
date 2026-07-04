<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Webhook } from '@lucide/vue';
import { ref, watch } from 'vue';
import WebhookLogController from '@/actions/App/Http/Controllers/Admin/WebhookLogController';
import { Pill, SvcChip, Toggle } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';

interface WebhookEvent {
    id: number;
    service_name: string | null;
    service_type: string | null;
    event_type: string | null;
    created_at: string | null;
    processed_at: string | null;
    payload_hash: string | null;
}

interface PaginatorLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatorMeta {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

interface ServiceOption {
    id: number;
    name: string;
    type: string;
}

const props = defineProps<{
    events: {
        data: WebhookEvent[];
        links: PaginatorLink[];
        meta: PaginatorMeta;
    };
    filters: { service_id: number | null; event_type: string };
    filterOptions: { services: ServiceOption[]; eventTypes: string[] };
    settings: { capture_enabled: boolean };
}>();

const captureEnabled = ref(props.settings.capture_enabled);
const updatingCapture = ref(false);

watch(
    () => props.settings.capture_enabled,
    (value) => {
        captureEnabled.value = value;
    },
);

function setCapture(value: boolean): void {
    if (updatingCapture.value || value === captureEnabled.value) {
        return;
    }

    updatingCapture.value = true;
    captureEnabled.value = value;

    router.put(
        WebhookLogController.updateSettings.url(),
        { capture_enabled: value },
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                captureEnabled.value = !value;
            },
            onFinish: () => {
                updatingCapture.value = false;
            },
        },
    );
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'Webhook log', href: WebhookLogController.index.url() },
        ],
    },
});

function applyFilters(next: {
    service_id?: number | null;
    event_type?: string;
}) {
    const merged = {
        service_id:
            'service_id' in next ? next.service_id : props.filters.service_id,
        event_type:
            'event_type' in next
                ? (next.event_type ?? '')
                : props.filters.event_type,
    };

    const query: Record<string, string | number> = {};

    if (merged.service_id) {
        query.service_id = merged.service_id;
    }

    if (merged.event_type) {
        query.event_type = merged.event_type;
    }

    router.get(WebhookLogController.index.url(), query, {
        preserveScroll: true,
        replace: true,
    });
}

function setService(value: string) {
    applyFilters({ service_id: value === 'all' ? null : Number(value) });
}

function setEventType(value: string) {
    applyFilters({ event_type: value === 'all' ? '' : value });
}

function goToPage(url: string | null) {
    if (!url) {
        return;
    }

    router.get(url, {}, { preserveScroll: true });
}

function formatTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString();
}

function svcId(type: string | null): string {
    if (!type) {
        return 'system';
    }

    return type;
}
</script>

<template>
    <Head title="Webhook log" />

    <div class="flex flex-col gap-4 p-5">
        <div class="flex items-end justify-between gap-3">
            <div>
                <div class="mb-1.5 text-[13px] text-muted-foreground">
                    Admin <span class="text-fg-subtle">/</span> Webhook log
                </div>
                <h1
                    class="text-[22px] leading-tight font-semibold tracking-tight"
                >
                    Webhook log
                </h1>
                <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                    Every incoming webhook with its raw payload — useful for
                    debugging integrations and auditing what services are
                    actually sending.
                </p>
            </div>
            <div
                class="flex flex-col items-end gap-1 rounded-xl border border-border bg-card px-3 py-2"
            >
                <span
                    class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                    >Capture &amp; store</span
                >
                <Toggle
                    :model-value="captureEnabled"
                    :disabled="updatingCapture"
                    :label="captureEnabled ? 'Storing payloads' : 'Discarding'"
                    @update:model-value="setCapture"
                />
            </div>
        </div>

        <div
            class="flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card p-3"
        >
            <span
                class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
            >
                Filter
            </span>
            <Select
                :model-value="
                    filters.service_id ? String(filters.service_id) : 'all'
                "
                @update:model-value="
                    (v) => typeof v === 'string' && setService(v)
                "
            >
                <SelectTrigger class="h-7 w-40 text-xs">
                    <SelectValue placeholder="Service" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All services</SelectItem>
                    <SelectItem
                        v-for="service in filterOptions.services"
                        :key="service.id"
                        :value="String(service.id)"
                    >
                        {{ service.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Select
                :model-value="filters.event_type || 'all'"
                @update:model-value="
                    (v) => typeof v === 'string' && setEventType(v)
                "
            >
                <SelectTrigger class="h-7 w-44 text-xs">
                    <SelectValue placeholder="Event type" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All events</SelectItem>
                    <SelectItem
                        v-for="type in filterOptions.eventTypes"
                        :key="type"
                        :value="type"
                    >
                        {{ type }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <span
                class="font-mono-tabular ml-auto text-[11.5px] text-muted-foreground"
            >
                {{ events.meta.total }} events
            </span>
        </div>

        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th
                                v-for="h in [
                                    'Received',
                                    'Service',
                                    'Event',
                                    'Processed',
                                    'Hash',
                                    '',
                                ]"
                                :key="h"
                                class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                {{ h }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="event in events.data"
                            :key="event.id"
                            class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                        >
                            <td
                                class="font-mono-tabular px-3 py-2.5 text-[11.5px] whitespace-nowrap text-fg-subtle"
                            >
                                {{ formatTime(event.created_at) }}
                            </td>
                            <td class="px-3 py-2.5">
                                <span class="inline-flex items-center gap-2">
                                    <SvcChip
                                        v-if="event.service_type"
                                        :id="svcId(event.service_type)"
                                    />
                                    <span>{{ event.service_name ?? '—' }}</span>
                                </span>
                            </td>
                            <td
                                class="font-mono-tabular px-3 py-2.5 text-[12px]"
                            >
                                {{ event.event_type ?? '—' }}
                            </td>
                            <td class="px-3 py-2.5">
                                <Pill
                                    :variant="
                                        event.processed_at ? 'ok' : 'warn'
                                    "
                                    :dot="!!event.processed_at"
                                >
                                    {{
                                        event.processed_at
                                            ? 'processed'
                                            : 'pending'
                                    }}
                                </Pill>
                            </td>
                            <td
                                class="font-mono-tabular px-3 py-2.5 text-[10.5px] text-fg-subtle"
                            >
                                <span :title="event.payload_hash ?? ''">{{
                                    event.payload_hash
                                        ? event.payload_hash.slice(0, 10)
                                        : '—'
                                }}</span>
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <Link
                                    :href="
                                        WebhookLogController.show.url(event.id)
                                    "
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="h-7 px-2 text-xs"
                                    >
                                        <Webhook class="size-3.5" />View
                                    </Button>
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="events.data.length === 0">
                            <td
                                colspan="6"
                                class="px-3 py-12 text-center text-sm text-fg-subtle"
                            >
                                No webhook events recorded yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div
            v-if="events.meta.last_page > 1"
            class="flex flex-wrap items-center justify-between gap-2"
        >
            <p class="text-sm text-muted-foreground">
                Page {{ events.meta.current_page }} of
                {{ events.meta.last_page }} —
                <span class="font-mono-tabular">{{ events.meta.total }}</span>
                events
            </p>
            <div class="flex flex-wrap gap-1">
                <Button
                    v-for="link in events.links"
                    :key="link.label"
                    :variant="link.active ? 'default' : 'outline'"
                    size="sm"
                    :disabled="!link.url"
                    @click="goToPage(link.url)"
                >
                    <span v-html="link.label" />
                </Button>
            </div>
        </div>
    </div>
</template>
