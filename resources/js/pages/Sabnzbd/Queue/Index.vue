<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pause, Play, RefreshCw, RotateCcw, Trash2 } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import QueueController from '@/actions/App/Http/Controllers/Sabnzbd/QueueController';
import { OpenInServiceButton, Pill, StatCard } from '@/components/mm';
import { HistoryDeleteDialog, SpeedLimitControl } from '@/components/sabnzbd';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCan } from '@/composables/useCan';
import { dashboard } from '@/routes';

interface QueueSlot {
    nzo_id: string;
    filename: string | null;
    cat: string | null;
    size: string | null;
    sizeleft: string | null;
    percentage: string | null;
    timeleft: string | null;
    status: string | null;
    priority: number | string | null;
}

interface HistorySlot {
    nzo_id: string;
    name: string | null;
    category: string | null;
    size: number | string | null;
    status: string | null;
    fail_message: string | null;
    completed: number | null;
}

interface Queue {
    paused?: boolean;
    speed?: string | null;
    sizeleft?: string | null;
    timeleft?: string | null;
    speedlimit?: string | null;
    speedlimit_abs?: string | null;
    noofslots?: number;
    slots?: QueueSlot[];
}

interface SabnzbdConnection {
    id: number;
    name: string;
    url: string;
}

interface History {
    slots: HistorySlot[];
    total: number;
    page: number;
    page_size: number;
}

const props = defineProps<{
    configured: boolean;
    connection: SabnzbdConnection | null;
    queue: Queue;
    history: History;
    paused: boolean;
    error?: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Media', href: dashboard().url },
            { title: 'SABnzbd', href: QueueController.index.url() },
        ],
    },
});

const PRIORITY_LABELS: Record<string, string> = {
    '-1': 'Low',
    '0': 'Normal',
    '1': 'High',
    '2': 'Force',
};

const { can } = useCan();
const isAdmin = computed(() => can('admin'));

// Every prop the index can change: a poll that hits an outage must also
// bring `error` (and the connection) along, and a recovered one clears it.
const POLLED_PROPS = [
    'queue',
    'history',
    'paused',
    'error',
    'connection',
    'configured',
];

let pollHandle: ReturnType<typeof setInterval> | null = null;
const pagingHistory = ref(false);

onMounted(() => {
    // reload() keeps the current URL, so ?history_page survives polling.
    // A poll fired mid-paging would still carry the old page and revert it.
    pollHandle = setInterval(() => {
        if (!pagingHistory.value) {
            router.reload({ only: POLLED_PROPS });
        }
    }, 5000);
});

onUnmounted(() => {
    if (pollHandle !== null) {
        clearInterval(pollHandle);
    }
});

function refresh(): void {
    router.reload({ only: POLLED_PROPS });
}

function toggleQueue(): void {
    const action = props.paused
        ? QueueController.resumeQueue()
        : QueueController.pauseQueue();
    router.visit(action.url, { method: action.method, preserveScroll: true });
}

function pauseSlot(nzoId: string): void {
    const action = QueueController.pauseSlot(nzoId);
    router.visit(action.url, { method: action.method, preserveScroll: true });
}

function resumeSlot(nzoId: string): void {
    const action = QueueController.resumeSlot(nzoId);
    router.visit(action.url, { method: action.method, preserveScroll: true });
}

function deleteSlot(nzoId: string, filename: string | null): void {
    if (!confirm(`Remove "${filename ?? nzoId}" from the queue?`)) {
        return;
    }

    const action = QueueController.deleteSlot(nzoId);
    router.visit(action.url, { method: action.method, preserveScroll: true });
}

function changePriority(nzoId: string, priority: string): void {
    const action = QueueController.reprioritize(nzoId);
    router.visit(action.url, {
        method: action.method,
        data: { priority: Number(priority) },
        preserveScroll: true,
    });
}

const historyPage = computed(() => props.history.page ?? 1);
const historyLastPage = computed(() =>
    Math.max(
        1,
        Math.ceil((props.history.total ?? 0) / (props.history.page_size || 50)),
    ),
);

function goToHistoryPage(page: number): void {
    const url =
        page > 1
            ? QueueController.index.url({ query: { history_page: page } })
            : QueueController.index.url();

    // An in-flight poll was issued for the old page; its late response
    // would otherwise land after this visit and put the old page back.
    router.cancelAll();
    pagingHistory.value = true;

    router.get(
        url,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['history'],
            onFinish: () => {
                pagingHistory.value = false;
            },
        },
    );
}

// Past the last page (history shrank, or a stale link) Previous leads
// straight back to the last page instead of stepping through empty ones.
function goToPreviousHistoryPage(): void {
    goToHistoryPage(Math.min(historyPage.value - 1, historyLastPage.value));
}

const retrying = ref<string | null>(null);

function retryHistory(nzoId: string): void {
    if (retrying.value !== null) {
        return;
    }

    retrying.value = nzoId;
    const action = QueueController.retryHistory(nzoId);
    router.visit(action.url, {
        method: action.method,
        preserveScroll: true,
        onFinish: () => {
            retrying.value = null;
        },
    });
}

const deletingSlot = ref<HistorySlot | null>(null);
const deleteProcessing = ref(false);

function confirmDeleteHistory(withFiles: boolean): void {
    const slot = deletingSlot.value;

    if (slot === null) {
        return;
    }

    deleteProcessing.value = true;
    const action = QueueController.deleteHistory(slot.nzo_id);
    router.visit(action.url, {
        method: action.method,
        data: { with_files: withFiles },
        preserveScroll: true,
        onFinish: () => {
            deleteProcessing.value = false;
            deletingSlot.value = null;
        },
    });
}

function statusVariant(status: string | null): 'ok' | 'danger' | 'default' {
    const lower = (status ?? '').toLowerCase();

    if (lower === 'completed' || lower === 'ok') {
        return 'ok';
    }

    if (lower === 'failed') {
        return 'danger';
    }

    return 'default';
}
</script>

<template>
    <Head title="SABnzbd Queue" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero -->
        <div class="flex items-end justify-between gap-3">
            <div>
                <div class="mb-1.5 text-[13px] text-muted-foreground">
                    Media <span class="text-fg-subtle">/</span> SABnzbd
                </div>
                <h1
                    class="text-[22px] leading-tight font-semibold tracking-tight"
                >
                    Downloads
                </h1>
                <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                    Live SABnzbd queue and history.
                    {{
                        connection?.name
                            ? `Connected to ${connection.name}.`
                            : ''
                    }}
                </p>
            </div>
            <div class="flex gap-2" v-if="configured">
                <SpeedLimitControl
                    v-if="isAdmin && !error"
                    :percent="queue.speedlimit ?? null"
                    :absolute="queue.speedlimit_abs ?? null"
                />
                <OpenInServiceButton
                    :href="props.connection?.url"
                    label="Open SABnzbd"
                />
                <Button
                    size="sm"
                    variant="outline"
                    class="h-7 gap-1.5 text-xs"
                    @click="refresh"
                >
                    <RefreshCw class="size-3.5" /> Refresh
                </Button>
                <Button
                    size="sm"
                    class="h-7 gap-1.5 text-xs"
                    :variant="paused ? 'default' : 'outline'"
                    @click="toggleQueue"
                >
                    <component :is="paused ? Play : Pause" class="size-3.5" />
                    {{ paused ? 'Resume queue' : 'Pause queue' }}
                </Button>
            </div>
        </div>

        <!-- Empty state -->
        <div
            v-if="!configured"
            class="rounded-xl border border-border bg-card p-8 text-center text-sm text-muted-foreground"
        >
            No active SABnzbd connection. Add one in Admin → Connections to
            start tracking downloads here.
        </div>

        <div
            v-else-if="error"
            class="rounded-xl border border-destructive/40 bg-destructive/10 p-4 text-sm text-destructive"
            data-sabnzbd-error
        >
            {{ error }}
        </div>

        <template v-else>
            <!-- Stats -->
            <div class="grid gap-4 md:grid-cols-4">
                <StatCard
                    label="Speed"
                    :value="queue.speed ?? '—'"
                    hint="current download rate"
                />
                <StatCard
                    label="Remaining"
                    :value="queue.sizeleft ?? '—'"
                    hint="size left in queue"
                />
                <StatCard
                    label="ETA"
                    :value="queue.timeleft ?? '—'"
                    hint="time until queue empty"
                />
                <StatCard
                    label="In queue"
                    :value="queue.slots?.length ?? 0"
                    hint="active jobs"
                />
            </div>

            <!-- Queue table -->
            <div
                class="overflow-hidden rounded-xl border border-border bg-card"
            >
                <div
                    class="border-b border-border px-4 py-3 text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                >
                    Active queue
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-[13px]">
                        <thead>
                            <tr>
                                <th
                                    v-for="h in [
                                        'Name',
                                        'Category',
                                        'Size',
                                        'Progress',
                                        'ETA',
                                        'Priority',
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
                                v-for="slot in queue.slots ?? []"
                                :key="slot.nzo_id"
                                class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                            >
                                <td class="px-3 py-2.5">
                                    <div
                                        class="font-mono-tabular text-[12.5px] font-medium"
                                    >
                                        {{ slot.filename }}
                                    </div>
                                </td>
                                <td class="px-3 py-2.5">
                                    <Pill v-if="slot.cat">{{ slot.cat }}</Pill>
                                </td>
                                <td class="font-mono-tabular px-3 py-2.5">
                                    {{ slot.size }}
                                </td>
                                <td class="font-mono-tabular px-3 py-2.5">
                                    {{ slot.percentage }}%
                                </td>
                                <td class="font-mono-tabular px-3 py-2.5">
                                    {{ slot.timeleft }}
                                </td>
                                <td class="px-3 py-2.5">
                                    <Select
                                        :model-value="String(slot.priority)"
                                        @update:model-value="
                                            (v) =>
                                                changePriority(
                                                    slot.nzo_id,
                                                    String(v),
                                                )
                                        "
                                    >
                                        <SelectTrigger class="h-7 w-24 text-xs">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                v-for="(
                                                    label, value
                                                ) in PRIORITY_LABELS"
                                                :key="value"
                                                :value="value"
                                            >
                                                {{ label }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </td>
                                <td class="px-3 py-2.5 text-right">
                                    <div class="flex justify-end gap-1">
                                        <Button
                                            v-if="slot.status === 'Paused'"
                                            variant="ghost"
                                            size="sm"
                                            class="h-7 px-2 text-xs"
                                            @click="resumeSlot(slot.nzo_id)"
                                        >
                                            <Play class="size-3.5" />
                                        </Button>
                                        <Button
                                            v-else
                                            variant="ghost"
                                            size="sm"
                                            class="h-7 px-2 text-xs"
                                            @click="pauseSlot(slot.nzo_id)"
                                        >
                                            <Pause class="size-3.5" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="size-7 p-0 text-destructive hover:text-destructive"
                                            @click="
                                                deleteSlot(
                                                    slot.nzo_id,
                                                    slot.filename,
                                                )
                                            "
                                        >
                                            <Trash2 class="size-3.5" />
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="(queue.slots ?? []).length === 0">
                                <td
                                    colspan="7"
                                    class="px-3 py-8 text-center text-sm text-fg-subtle"
                                >
                                    Queue is empty.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- History (paged) -->
            <div
                class="overflow-hidden rounded-xl border border-border bg-card"
                data-sabnzbd-history
            >
                <div
                    class="flex items-center justify-between border-b border-border px-4 py-3"
                >
                    <span
                        class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                        >History</span
                    >
                    <span
                        class="font-mono-tabular text-[11.5px] text-muted-foreground"
                        >{{ history.total }} jobs</span
                    >
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-[13px]">
                        <thead>
                            <tr>
                                <th
                                    v-for="h in [
                                        'Name',
                                        'Category',
                                        'Status',
                                        'Note',
                                        ...(isAdmin ? [''] : []),
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
                                v-for="slot in history.slots"
                                :key="slot.nzo_id"
                                :data-history-row="slot.nzo_id"
                                class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                            >
                                <td class="px-3 py-2.5">
                                    <div
                                        class="font-mono-tabular text-[12.5px] font-medium"
                                    >
                                        {{ slot.name }}
                                    </div>
                                </td>
                                <td class="px-3 py-2.5">
                                    <Pill v-if="slot.category">{{
                                        slot.category
                                    }}</Pill>
                                </td>
                                <td class="px-3 py-2.5">
                                    <Pill :variant="statusVariant(slot.status)">
                                        {{ slot.status ?? '—' }}
                                    </Pill>
                                </td>
                                <td class="px-3 py-2.5 text-xs text-fg-subtle">
                                    {{ slot.fail_message || '—' }}
                                </td>
                                <td
                                    v-if="isAdmin"
                                    class="px-3 py-2.5 text-right"
                                >
                                    <div class="flex justify-end gap-1">
                                        <Button
                                            v-if="slot.status === 'Failed'"
                                            variant="ghost"
                                            size="sm"
                                            class="h-7 gap-1 px-2 text-xs"
                                            :disabled="retrying !== null"
                                            data-history-retry
                                            @click="retryHistory(slot.nzo_id)"
                                        >
                                            <RotateCcw class="size-3.5" />Retry
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="size-7 p-0 text-destructive hover:text-destructive"
                                            :aria-label="`Remove ${slot.name ?? slot.nzo_id} from history`"
                                            data-history-delete
                                            @click="deletingSlot = slot"
                                        >
                                            <Trash2 class="size-3.5" />
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="history.slots.length === 0">
                                <td
                                    :colspan="isAdmin ? 5 : 4"
                                    class="px-3 py-8 text-center text-sm text-fg-subtle"
                                >
                                    {{
                                        history.total > 0
                                            ? 'No downloads on this page.'
                                            : 'No recent downloads.'
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-if="historyLastPage > 1 || historyPage > historyLastPage"
                    class="flex items-center justify-between gap-2 border-t border-border px-4 py-2.5"
                >
                    <span
                        class="text-[12px] text-muted-foreground"
                        data-history-page
                        >Page {{ historyPage }} of {{ historyLastPage }}</span
                    >
                    <div class="flex gap-1">
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-7 text-xs"
                            :disabled="historyPage <= 1"
                            data-history-prev
                            @click="goToPreviousHistoryPage"
                        >
                            Previous
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-7 text-xs"
                            :disabled="historyPage >= historyLastPage"
                            data-history-next
                            @click="goToHistoryPage(historyPage + 1)"
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </div>
        </template>

        <HistoryDeleteDialog
            :open="deletingSlot !== null"
            :name="deletingSlot?.name ?? null"
            :processing="deleteProcessing"
            @update:open="(value) => !value && (deletingSlot = null)"
            @confirm="confirmDeleteHistory"
        />
    </div>
</template>
