<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ExternalLink,
    Loader2,
    MoreVertical,
    RefreshCcw,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import LibraryActivityController from '@/actions/App/Http/Controllers/Library/ActivityController';
import { Pill, SvcChip } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
    download_id: string | null;
}

interface QueuePayload {
    rows: QueueRow[];
    errors: string[];
    services: { sonarr?: boolean; radarr?: boolean };
}

interface HistoryRow {
    id: number;
    service: 'sonarr' | 'radarr';
    service_url: string;
    event_type: string | null;
    title: string | null;
    subtitle: string | null;
    source_title: string | null;
    quality: string | null;
    download_client: string | null;
    date: string | null;
    data: Record<string, unknown> | null;
}

interface HistoryPayload {
    rows: HistoryRow[];
    errors: string[];
    services: { sonarr?: boolean; radarr?: boolean };
}

interface ManualImportEpisode {
    season: number | null;
    episode: number | null;
    title: string | null;
}

interface ManualImportRejection {
    reason: string;
    type: string | null;
}

interface ManualImportCandidate {
    path: string | null;
    name: string | null;
    size: number | null;
    quality: string | null;
    release_group: string | null;
    languages: string[];
    rejections: ManualImportRejection[];
    series_title?: string | null;
    season?: number | null;
    episodes?: ManualImportEpisode[];
    movie_title?: string | null;
    movie_year?: number | null;
}

const props = defineProps<{
    queue?: QueuePayload;
    history?: HistoryPayload;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Media', href: dashboard().url },
            {
                title: 'Library activity',
                href: LibraryActivityController.queue.url(),
            },
        ],
    },
});

const page = usePage();
const isAdmin = computed(() => {
    const role = page.props.auth.user?.role;

    if (!role) {
        return false;
    }

    const value = typeof role === 'string' ? role : role.value;

    return value === 'admin';
});

const refreshing = ref(false);
const serviceFilter = ref<'all' | 'sonarr' | 'radarr'>('all');
const activeTab = ref<'queue' | 'history'>('queue');

const filteredHistoryRows = computed<HistoryRow[]>(() => {
    const all = props.history?.rows ?? [];

    if (serviceFilter.value === 'all') {
        return all;
    }

    return all.filter((row) => row.service === serviceFilter.value);
});

function eventVariant(
    eventType: string | null,
): 'ok' | 'warn' | 'danger' | 'info' | 'default' {
    switch (eventType) {
        case 'downloadFolderImported':
        case 'movieFolderImported':
            return 'ok';
        case 'downloadFailed':
            return 'danger';
        case 'downloadIgnored':
        case 'episodeFileDeleted':
        case 'movieFileDeleted':
            return 'warn';
        case 'grabbed':
            return 'info';
        default:
            return 'default';
    }
}

function eventLabel(eventType: string | null): string {
    if (!eventType) {
        return '—';
    }

    switch (eventType) {
        case 'downloadFolderImported':
        case 'movieFolderImported':
            return 'imported';
        case 'episodeFileDeleted':
            return 'episode deleted';
        case 'movieFileDeleted':
            return 'movie deleted';
        case 'downloadFailed':
            return 'failed';
        case 'downloadIgnored':
            return 'ignored';
    }

    return eventType
        .replace(/([A-Z])/g, ' $1')
        .toLowerCase()
        .trim();
}

function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const d = new Date(iso);

    return d.toLocaleString([], {
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}
const acting = ref<string | null>(null);

function actionKey(row: QueueRow, verb: string): string {
    return `${row.service}-${row.id}-${verb}`;
}

function forceGrab(row: QueueRow): void {
    if (
        !confirm(
            `Force grab "${row.title ?? 'this item'}" now? This bypasses the RSS sync delay.`,
        )
    ) {
        return;
    }

    const key = actionKey(row, 'grab');
    acting.value = key;
    router.post(
        LibraryActivityController.grabQueueItem.url({
            service: row.service,
            id: row.id,
        }),
        {},
        {
            preserveScroll: true,
            onSuccess: () => router.reload({ only: ['queue'] }),
            onFinish: () => {
                if (acting.value === key) {
                    acting.value = null;
                }
            },
        },
    );
}

function removeQueueItem(row: QueueRow, verb: 'remove' | 'block'): void {
    const promptCopy =
        verb === 'block'
            ? `Remove "${row.title ?? 'this item'}" and blocklist the release so a fresh search runs?`
            : `Remove "${row.title ?? 'this item'}" from the queue?`;

    if (!confirm(promptCopy)) {
        return;
    }

    const key = actionKey(row, verb);
    acting.value = key;
    router.post(
        LibraryActivityController.removeQueueItem.url({
            service: row.service,
            id: row.id,
        }),
        { verb },
        {
            preserveScroll: true,
            onSuccess: () => router.reload({ only: ['queue'] }),
            onFinish: () => {
                if (acting.value === key) {
                    acting.value = null;
                }
            },
        },
    );
}

// Manual import dialog state.
const importingRow = ref<QueueRow | null>(null);
const importLoading = ref(false);
const importError = ref<string | null>(null);
const importCandidates = ref<ManualImportCandidate[]>([]);
const importSubmitting = ref(false);

function openManualImport(row: QueueRow): void {
    if (!row.download_id) {
        return;
    }

    importingRow.value = row;
    importCandidates.value = [];
    importError.value = null;
    importLoading.value = true;

    fetch(
        LibraryActivityController.manualImportCandidates.url({
            service: row.service,
            downloadId: row.download_id,
        }),
        { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
    )
        .then(async (response) => {
            const body = await response.json();

            if (!response.ok) {
                importError.value =
                    body.error ?? `Request failed (${response.status})`;

                return;
            }

            importCandidates.value = body.candidates ?? [];
        })
        .catch((error: unknown) => {
            importError.value =
                error instanceof Error ? error.message : 'Network error';
        })
        .finally(() => {
            importLoading.value = false;
        });
}

function closeManualImport(): void {
    if (importSubmitting.value) {
        return;
    }

    importingRow.value = null;
    importCandidates.value = [];
    importError.value = null;
}

function submitManualImport(): void {
    const row = importingRow.value;

    if (!row || !row.download_id) {
        return;
    }

    importSubmitting.value = true;
    router.post(
        LibraryActivityController.executeManualImport.url({
            service: row.service,
        }),
        { download_id: row.download_id },
        {
            preserveScroll: true,
            onSuccess: () => {
                importingRow.value = null;
                router.reload({ only: ['queue'] });
            },
            onFinish: () => {
                importSubmitting.value = false;
            },
        },
    );
}

function importableCount(): number {
    return importCandidates.value.filter((c) => c.rejections.length === 0)
        .length;
}

function candidateLabel(candidate: ManualImportCandidate): string {
    if (candidate.series_title) {
        const ep = (candidate.episodes ?? [])
            .map((e) =>
                e.season !== null && e.episode !== null
                    ? `S${String(e.season).padStart(2, '0')}E${String(e.episode).padStart(2, '0')}`
                    : '',
            )
            .filter(Boolean)
            .join(', ');

        return ep
            ? `${candidate.series_title} · ${ep}`
            : (candidate.series_title ?? '');
    }

    if (candidate.movie_title) {
        return candidate.movie_year
            ? `${candidate.movie_title} (${candidate.movie_year})`
            : candidate.movie_title;
    }

    return candidate.name ?? '—';
}

function refresh(): void {
    if (refreshing.value) {
        return;
    }

    refreshing.value = true;
    router.reload({
        only: activeTab.value === 'queue' ? ['queue'] : ['history'],
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

    if (
        row.tracked_state === 'importBlocked' ||
        row.tracked_state === 'importPending'
    ) {
        return 'warn';
    }

    return row.tracked_state === 'imported' ? 'ok' : 'info';
}

function statusLabel(row: QueueRow): string {
    const raw = row.tracked_state ?? row.status ?? 'unknown';

    switch (raw) {
        case 'importPending':
            return 'import pending';
        case 'importBlocked':
            return 'import blocked';
        case 'importing':
            return 'importing';
        case 'imported':
            return 'imported';
        case 'failedPending':
            return 'failed pending';
        case 'downloadClientUnavailable':
            return 'client offline';
        case 'downloading':
            return 'downloading';
        case 'queued':
            return 'queued';
        case 'paused':
            return 'paused';
        case 'completed':
            return 'completed';
        case 'failed':
            return 'failed';
        case 'warning':
            return 'warning';
        case 'delay':
            return 'delayed';
        case 'fallback':
            return 'fallback';
        case 'ignored':
            return 'ignored';
    }

    return raw
        .replace(/([A-Z])/g, ' $1')
        .toLowerCase()
        .trim();
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
                <h1
                    class="text-[22px] leading-tight font-semibold tracking-tight"
                >
                    Library activity
                </h1>
                <p
                    v-if="activeTab === 'queue'"
                    class="mt-1 max-w-[640px] text-[13px] text-muted-foreground"
                >
                    Combined Sonarr + Radarr download queue. Stuck imports
                    surface here with their tracked state so you can act before
                    falling behind.
                </p>
                <p
                    v-else
                    class="mt-1 max-w-[640px] text-[13px] text-muted-foreground"
                >
                    Recent grabs, imports, deletions, and failures from both
                    services — newest first.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <div
                    class="inline-flex h-7 items-center rounded-md border border-border bg-card p-0.5"
                    role="tablist"
                    aria-label="Activity view"
                >
                    <button
                        v-for="tab in ['queue', 'history'] as const"
                        :key="tab"
                        type="button"
                        :class="[
                            'inline-flex h-6 items-center rounded-[4px] px-2.5 text-[11.5px] font-medium capitalize transition-colors',
                            activeTab === tab
                                ? 'bg-accent text-accent-foreground'
                                : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                        ]"
                        @click="activeTab = tab"
                    >
                        {{ tab }}
                    </button>
                </div>
                <div
                    class="inline-flex h-7 items-center rounded-md border border-border bg-card p-0.5"
                    role="tablist"
                >
                    <button
                        v-for="value in ['all', 'sonarr', 'radarr'] as const"
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

        <!-- Queue tab -->
        <template v-if="activeTab === 'queue'">
            <!-- Errors -->
            <div
                v-if="queue && queue.errors.length > 0"
                class="border-warn/30 bg-warn/10 text-warn rounded-md border px-3 py-2 text-[12px]"
            >
                <div v-for="(error, index) in queue.errors" :key="index">
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
                <template
                    v-if="!queue.services.sonarr && !queue.services.radarr"
                >
                    No active Sonarr or Radarr connection configured.
                </template>
                <template v-else>
                    Nothing in the queue right now — both services are caught
                    up.
                </template>
            </div>

            <!-- Rows -->
            <div
                v-else
                class="overflow-hidden rounded-xl border border-border bg-card"
            >
                <div class="overflow-x-auto">
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
                                    <div class="font-medium">
                                        {{ row.title ?? '—' }}
                                    </div>
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
                                        v-for="(
                                            message, mi
                                        ) in row.status_messages"
                                        :key="mi"
                                        class="text-warn mt-1 text-[11.5px]"
                                    >
                                        <span class="font-medium"
                                            >{{ message.title }}:</span
                                        >
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
                                <td
                                    class="font-mono-tabular px-3 py-2.5 text-right text-[12px]"
                                >
                                    <div>{{ formatBytes(row.size) }}</div>
                                    <div
                                        class="text-[11px] text-muted-foreground"
                                    >
                                        {{ progress(row) }}%
                                    </div>
                                </td>
                                <td
                                    class="font-mono-tabular px-3 py-2.5 text-right text-[12px]"
                                >
                                    {{ timeleftLabel(row) }}
                                </td>
                                <td class="px-3 py-2.5 text-right">
                                    <div
                                        class="flex items-center justify-end gap-1"
                                    >
                                        <a
                                            :href="`${row.service_url}/activity/queue`"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="inline-flex items-center gap-1 text-[12px] text-muted-foreground hover:text-foreground"
                                        >
                                            <ExternalLink
                                                class="size-3.5"
                                            />Open
                                        </a>
                                        <DropdownMenu v-if="isAdmin">
                                            <DropdownMenuTrigger as-child>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    class="size-7 p-0"
                                                    :disabled="
                                                        acting ===
                                                            actionKey(
                                                                row,
                                                                'remove',
                                                            ) ||
                                                        acting ===
                                                            actionKey(
                                                                row,
                                                                'block',
                                                            )
                                                    "
                                                    :aria-label="`Manage ${row.title ?? 'queue item'}`"
                                                >
                                                    <MoreVertical
                                                        class="size-3.5"
                                                    />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent
                                                align="end"
                                                class="w-52"
                                            >
                                                <DropdownMenuLabel
                                                    >Manage queue
                                                    item</DropdownMenuLabel
                                                >
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    @select="forceGrab(row)"
                                                >
                                                    Force grab now
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    :disabled="!row.download_id"
                                                    @select="
                                                        openManualImport(row)
                                                    "
                                                >
                                                    Manual import…
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    @select="
                                                        removeQueueItem(
                                                            row,
                                                            'remove',
                                                        )
                                                    "
                                                >
                                                    Remove from queue
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    class="text-destructive focus:text-destructive"
                                                    @select="
                                                        removeQueueItem(
                                                            row,
                                                            'block',
                                                        )
                                                    "
                                                >
                                                    Blocklist & retry
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <!-- History tab -->
        <template v-if="activeTab === 'history'">
            <div
                v-if="history && history.errors.length > 0"
                class="border-warn/30 bg-warn/10 text-warn rounded-md border px-3 py-2 text-[12px]"
            >
                <div v-for="(error, index) in history.errors" :key="index">
                    {{ error }}
                </div>
            </div>

            <div v-if="!history" class="space-y-2">
                <Skeleton v-for="n in 8" :key="n" class="h-12 w-full" />
            </div>

            <div
                v-else-if="filteredHistoryRows.length === 0"
                class="rounded-xl border border-border bg-card px-4 py-10 text-center text-sm text-muted-foreground"
            >
                <template
                    v-if="!history.services.sonarr && !history.services.radarr"
                >
                    No active Sonarr or Radarr connection configured.
                </template>
                <template v-else> No recent history yet. </template>
            </div>

            <div
                v-else
                class="overflow-x-auto rounded-xl border border-border bg-card"
            >
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-[13px]">
                        <thead>
                            <tr>
                                <th
                                    v-for="header in [
                                        'Service',
                                        'Event',
                                        'Title',
                                        'Quality',
                                        'When',
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
                                v-for="row in filteredHistoryRows"
                                :key="`${row.service}-${row.id}`"
                                class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                            >
                                <td class="px-3 py-2.5">
                                    <SvcChip :id="row.service" />
                                </td>
                                <td class="px-3 py-2.5">
                                    <Pill
                                        :variant="eventVariant(row.event_type)"
                                    >
                                        {{ eventLabel(row.event_type) }}
                                    </Pill>
                                </td>
                                <td class="px-3 py-2.5">
                                    <div class="font-medium">
                                        {{ row.title ?? '—' }}
                                    </div>
                                    <div
                                        v-if="row.subtitle"
                                        class="text-[11.5px] text-muted-foreground"
                                    >
                                        {{ row.subtitle }}
                                    </div>
                                    <div
                                        v-if="row.source_title"
                                        class="font-mono-tabular mt-1 text-[11px] break-all text-fg-subtle"
                                    >
                                        {{ row.source_title }}
                                    </div>
                                </td>
                                <td class="px-3 py-2.5 text-[12px]">
                                    {{ row.quality ?? '—' }}
                                </td>
                                <td
                                    class="font-mono-tabular px-3 py-2.5 text-[12px] text-muted-foreground"
                                >
                                    {{ formatDate(row.date) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <!-- Manual import dialog -->
        <Dialog
            :open="importingRow !== null"
            @update:open="(v) => !v && closeManualImport()"
        >
            <DialogContent v-if="importingRow" class="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        Manual import — {{ importingRow.title ?? 'queue item' }}
                    </DialogTitle>
                </DialogHeader>
                <div
                    v-if="importLoading"
                    class="flex items-center gap-2 py-6 text-sm text-muted-foreground"
                >
                    <Loader2 class="size-4 animate-spin" />
                    Discovering candidates…
                </div>
                <div
                    v-else-if="importError"
                    class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-[12px] text-destructive"
                >
                    {{ importError }}
                </div>
                <div
                    v-else-if="importCandidates.length === 0"
                    class="rounded-md border border-border bg-bg-elev px-3 py-3 text-sm text-muted-foreground"
                >
                    No candidate files were returned. Sonarr/Radarr may not see
                    anything to import for this download yet.
                </div>
                <div v-else class="max-h-[55vh] space-y-2 overflow-y-auto">
                    <div
                        v-for="(candidate, idx) in importCandidates"
                        :key="idx"
                        class="rounded-md border border-border bg-card p-3"
                    >
                        <div class="text-[13px] font-medium">
                            {{ candidateLabel(candidate) }}
                        </div>
                        <div
                            class="mt-1 flex flex-wrap items-center gap-2 text-[11.5px] text-muted-foreground"
                        >
                            <Pill v-if="candidate.quality" variant="info">
                                {{ candidate.quality }}
                            </Pill>
                            <span v-if="candidate.release_group">{{
                                candidate.release_group
                            }}</span>
                            <span v-if="candidate.languages.length > 0">
                                {{ candidate.languages.join(', ') }}
                            </span>
                            <span v-if="candidate.size">{{
                                formatBytes(candidate.size)
                            }}</span>
                        </div>
                        <div
                            v-if="candidate.rejections.length > 0"
                            class="border-warn/30 bg-warn/5 text-warn mt-2 space-y-1 rounded border p-2 text-[11.5px]"
                        >
                            <div
                                v-for="(rejection, ri) in candidate.rejections"
                                :key="ri"
                                class="flex items-start gap-1.5"
                            >
                                <AlertTriangle class="mt-0.5 size-3" />
                                <span>{{ rejection.reason }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        :disabled="importSubmitting"
                        @click="closeManualImport"
                    >
                        Cancel
                    </Button>
                    <Button
                        :disabled="
                            importSubmitting ||
                            importLoading ||
                            importCandidates.length === 0
                        "
                        @click="submitManualImport"
                    >
                        <Loader2
                            v-if="importSubmitting"
                            class="mr-1.5 size-3.5 animate-spin"
                        />Import {{ importableCount() }} of
                        {{ importCandidates.length }} file(s)
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
