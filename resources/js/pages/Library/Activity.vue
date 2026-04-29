<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ExternalLink, RefreshCcw } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import LibraryActivityController from '@/actions/App/Http/Controllers/Library/ActivityController';
import { Pill, SvcChip } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { dashboard } from '@/routes';

interface QueueRow {
    id: number;
    service: 'sonarr' | 'radarr';
    service_url: string;
    title: string | null;
    subtitle: string | null;
    status: string | null;
    tracked_status: string | null;
    tracked_state: string | null;
    protocol: string | null;
    download_client: string | null;
    size: number | null;
    sizeleft: number | null;
    timeleft: string | null;
    estimated_completion_time: string | null;
    error_message: string | null;
    status_messages: { title: string; messages: string[] }[];
    added: string | null;
    quality: string | null;
}

interface QueuePayload {
    rows: QueueRow[];
    errors: string[];
    services: { sonarr?: boolean; radarr?: boolean };
}

const props = defineProps<{ queue?: QueuePayload }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Media', href: dashboard().url },
            { title: 'Library activity', href: LibraryActivityController.queue.url() },
        ],
    },
});

const refreshing = ref(false);
const serviceFilter = ref<'all' | 'sonarr' | 'radarr'>('all');

function refresh(): void {
    if (refreshing.value) {
        return;
    }

    refreshing.value = true;
    router.reload({
        only: ['queue'],
        onFinish: () => {
            refreshing.value = false;
        },
    });
}

function formatBytes(bytes: number | null): string {
    if (bytes === null || bytes <= 0) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unitIndex = 0;
    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex++;
    }

    return `${value.toFixed(value >= 100 || unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
}

function progress(row: QueueRow): number {
    if (!row.size || row.size <= 0) {
        return 0;
    }

    const done = row.size - (row.sizeleft ?? 0);

    return Math.max(0, Math.min(100, Math.round((done / row.size) * 100)));
}

function trackedVariant(row: QueueRow): 'ok' | 'warn' | 'danger' | 'info' {
    if (row.tracked_status === 'warning') {
        return 'warn';
    }

    if (row.tracked_status === 'error') {
        return 'danger';
    }

    if (row.tracked_state === 'importBlocked' || row.tracked_state === 'importPending') {
        return 'warn';
    }

    return row.tracked_state === 'imported' ? 'ok' : 'info';
}

function statusLabel(row: QueueRow): string {
    return row.tracked_state ?? row.status ?? 'unknown';
}

function timeleftLabel(row: QueueRow): string {
    if (row.status === 'completed' || (row.sizeleft ?? 0) === 0) {
        return '—';
    }

    return row.timeleft ?? '—';
}

const filteredRows = computed<QueueRow[]>(() => {
    const all = props.queue?.rows ?? [];
    if (serviceFilter.value === 'all') {
        return all;
    }

    return all.filter((row) => row.service === serviceFilter.value);
});
</script>

<template>
    <Head title="Library activity" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero -->
        <div class="flex items-end justify-between gap-3">
            <div>
                <h1 class="text-[22px] leading-tight font-semibold tracking-tight">
                    Library activity
                </h1>
                <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                    Combined Sonarr + Radarr download queue. Stuck imports
                    surface here with their tracked state so you can act
                    before falling behind.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <div
                    class="inline-flex h-7 items-center rounded-md border border-border bg-card p-0.5"
                    role="tablist"
                >
                    <button
                        v-for="value in (['all', 'sonarr', 'radarr'] as const)"
                        :key="value"
                        type="button"
                        :class="[
                            'inline-flex h-6 items-center rounded-[4px] px-2 text-[11.5px] font-medium transition-colors',
                            serviceFilter === value
                                ? 'bg-accent text-accent-foreground'
                                : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                        ]"
                        @click="serviceFilter = value"
                    >
                        {{ value === 'all' ? 'All' : value }}
                    </button>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    class="h-7 gap-1.5 text-xs"
                    :disabled="refreshing"
                    @click="refresh"
                >
                    <RefreshCcw
                        class="size-3.5"
                        :class="{ 'animate-spin': refreshing }"
                    />Refresh
                </Button>
            </div>
        </div>

        <!-- Errors -->
        <div
            v-if="queue && queue.errors.length > 0"
            class="rounded-md border border-warn/30 bg-warn/10 px-3 py-2 text-[12px] text-warn"
        >
            <div
                v-for="(error, index) in queue.errors"
                :key="index"
            >
                {{ error }}
            </div>
        </div>

        <!-- Loading -->
        <div v-if="!queue" class="space-y-2">
            <Skeleton v-for="n in 5" :key="n" class="h-14 w-full" />
        </div>

        <!-- Empty -->
        <div
            v-else-if="filteredRows.length === 0"
            class="rounded-xl border border-border bg-card px-4 py-10 text-center text-sm text-muted-foreground"
        >
            <template v-if="!queue.services.sonarr && !queue.services.radarr">
                No active Sonarr or Radarr connection configured.
            </template>
            <template v-else>
                Nothing in the queue right now — both services are caught up.
            </template>
        </div>

        <!-- Rows -->
        <div v-else class="overflow-hidden rounded-xl border border-border bg-card">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            v-for="header in [
                                'Service',
                                'Title',
                                'Quality',
                                'State',
                                'Size',
                                'Time left',
                                '',
                            ]"
                            :key="header"
                            class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            {{ header }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in filteredRows"
                        :key="`${row.service}-${row.id}`"
                        class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                    >
                        <td class="px-3 py-2.5">
                            <SvcChip :id="row.service" />
                        </td>
                        <td class="px-3 py-2.5">
                            <div class="font-medium">{{ row.title ?? '—' }}</div>
                            <div
                                v-if="row.subtitle"
                                class="text-[11.5px] text-muted-foreground"
                            >
                                {{ row.subtitle }}
                            </div>
                            <div
                                v-if="row.error_message"
                                class="mt-1 text-[11.5px] text-destructive"
                            >
                                {{ row.error_message }}
                            </div>
                            <div
                                v-for="(message, mi) in row.status_messages"
                                :key="mi"
                                class="mt-1 text-[11.5px] text-warn"
                            >
                                <span class="font-medium">{{ message.title }}:</span>
                                {{ message.messages.join('; ') }}
                            </div>
                        </td>
                        <td class="px-3 py-2.5 text-[12px]">
                            {{ row.quality ?? '—' }}
                        </td>
                        <td class="px-3 py-2.5">
                            <Pill :variant="trackedVariant(row)">
                                {{ statusLabel(row) }}
                            </Pill>
                        </td>
                        <td class="font-mono-tabular px-3 py-2.5 text-right text-[12px]">
                            <div>{{ formatBytes(row.size) }}</div>
                            <div class="text-[11px] text-muted-foreground">
                                {{ progress(row) }}%
                            </div>
                        </td>
                        <td class="font-mono-tabular px-3 py-2.5 text-right text-[12px]">
                            {{ timeleftLabel(row) }}
                        </td>
                        <td class="px-3 py-2.5 text-right">
                            <a
                                :href="`${row.service_url}/activity/queue`"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-[12px] text-muted-foreground hover:text-foreground"
                            >
                                <ExternalLink class="size-3.5" />Open
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
