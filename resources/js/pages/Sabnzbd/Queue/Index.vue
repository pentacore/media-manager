<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pause, Play, RefreshCw, Trash2 } from 'lucide-vue-next';
import { onMounted, onUnmounted } from 'vue';
import QueueController from '@/actions/App/Http/Controllers/Sabnzbd/QueueController';
import { OpenInServiceButton, Pill, StatCard } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';

interface QueueSlot {
    nzo_id: string;
    filename: string;
    cat: string | null;
    size: string;
    sizeleft: string;
    percentage: string;
    timeleft: string;
    status: string;
    priority: number | string;
}

interface HistorySlot {
    nzo_id: string;
    name: string;
    category: string | null;
    size: number | string;
    status: string;
    fail_message: string | null;
    completed: number | null;
}

interface Queue {
    paused?: boolean;
    speed?: string;
    sizeleft?: string;
    timeleft?: string;
    slots?: QueueSlot[];
    diskspace1?: string;
    diskspace2?: string;
}

interface SabnzbdConnection {
    id: number;
    name: string;
    url: string;
}

interface History {
    slots?: HistorySlot[];
    total_size?: string;
    month_size?: string;
    week_size?: string;
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

let pollHandle: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    pollHandle = setInterval(() => {
        router.reload({ only: ['queue', 'history', 'paused'] });
    }, 5000);
});

onUnmounted(() => {
    if (pollHandle !== null) {
        clearInterval(pollHandle);
    }
});

function refresh(): void {
    router.reload({ only: ['queue', 'history', 'paused'] });
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

function deleteSlot(nzoId: string, filename: string): void {
    if (!confirm(`Remove "${filename}" from the queue?`)) {
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

function statusVariant(status: string): 'ok' | 'danger' | 'default' {
    const lower = status.toLowerCase();

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
                    Live SABnzbd queue and recent history.
                    {{
                        connection?.name
                            ? `Connected to ${connection.name}.`
                            : ''
                    }}
                </p>
            </div>
            <div class="flex gap-2" v-if="configured">
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

            <!-- History table -->
            <div
                class="overflow-hidden rounded-xl border border-border bg-card"
            >
                <div
                    class="border-b border-border px-4 py-3 text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                >
                    Recent history
                </div>
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th
                                v-for="h in [
                                    'Name',
                                    'Category',
                                    'Status',
                                    'Note',
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
                            v-for="slot in history.slots ?? []"
                            :key="slot.nzo_id"
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
                                    {{ slot.status }}
                                </Pill>
                            </td>
                            <td class="px-3 py-2.5 text-xs text-fg-subtle">
                                {{ slot.fail_message || '—' }}
                            </td>
                        </tr>
                        <tr v-if="(history.slots ?? []).length === 0">
                            <td
                                colspan="4"
                                class="px-3 py-8 text-center text-sm text-fg-subtle"
                            >
                                No recent downloads.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>
