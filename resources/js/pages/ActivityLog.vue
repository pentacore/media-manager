<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Calendar,
    Cpu,
    Download,
    Sparkles,
} from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import ActivityLogController from '@/actions/App/Http/Controllers/ActivityLogController';
import { InitialsAvatar, Pill, SvcChip } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { useRealtimeList } from '@/composables/useRealtimeList';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { ActivityLogResource } from '@/typefinder/resources/ActivityLogResource';

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
    logs: {
        data: ActivityLogResource[];
        links: PaginatorLink[];
        meta: PaginatorMeta;
    };
    filters: {
        action: string;
        service_id: number | null;
    };
    filterOptions: {
        actions: string[];
        services: ServiceOption[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Live', href: dashboard().url },
            { title: 'Activity log', href: ActivityLogController().url },
        ],
    },
});

const hasFilter = computed(
    () => props.filters.action !== '' || props.filters.service_id !== null,
);
const onFirstPage = computed(() => props.logs.meta.current_page === 1);
const merge = computed(() => !hasFilter.value && onFirstPage.value);

const {
    items: liveLogs,
    staleCount,
    pause,
    resume,
    subscribe,
} = useRealtimeList<ActivityLogResource>({
    channel: 'activity',
    event: 'ActivityLogCreated',
    keyField: 'id',
    initial: props.logs.data,
    cap: props.logs.meta.per_page,
});

watch(
    merge,
    (canMerge) => {
        if (canMerge) {
            resume();
        } else {
            pause();
        }
    },
    { immediate: true },
);

onMounted(subscribe);

const visibleLogs = computed(() =>
    merge.value ? liveLogs.value : props.logs.data,
);

function refresh(): void {
    router.reload({ only: ['logs'], onSuccess: resume });
}

function formatTime(iso: string | null): string {
    if (!iso) {
return '—';
}

    const date = new Date(iso);

    return date.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    });
}

function applyFilters(next: { action?: string; service_id?: number | null }) {
    const merged = {
        action: next.action ?? props.filters.action,
        service_id: next.service_id ?? props.filters.service_id,
    };

    const query: Record<string, string | number> = {};

    if (merged.action) {
        query.action = merged.action;
    }

    if (merged.service_id) {
        query.service_id = merged.service_id;
    }

    router.get(ActivityLogController().url, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function svcId(name: string | null | undefined): string {
    if (!name) {
return 'system';
}

    const t = name.toLowerCase();

    if (t.includes('jellyseerr') || t.includes('seerr')) {
return 'seerr';
}

    if (t.includes('sonarr')) {
return 'sonarr';
}

    if (t.includes('radarr')) {
return 'radarr';
}

    if (t.includes('emby')) {
return 'emby';
}

    if (t.includes('prowlarr')) {
return 'prowlarr';
}

    return 'system';
}

function statusVariant(
    action: string,
): 'ok' | 'warn' | 'danger' | 'info' | 'default' {
    if (/delete|remove|fail/.test(action)) {
return 'danger';
}

    if (/timeout|degrad|warn/.test(action)) {
return 'warn';
}

    if (/import|grab|approve|complete|added/.test(action)) {
return 'ok';
}

    return 'info';
}

function channelLabel(action: string): string {
    if (/webhook|received/.test(action)) {
return 'webhook';
}

    if (/ai|tool/.test(action)) {
return 'ai';
}

    if (/monitor|health/.test(action)) {
return 'monitor';
}

    return 'web';
}

function goToPage(url: string | null) {
    if (!url) {
return;
}

    router.get(url, {}, { preserveState: true, preserveScroll: true });
}

const actorOptions = computed<string[]>(() => {
    const set = new Set<string>(['all']);

    for (const log of visibleLogs.value) {
        set.add(log.user_name ?? 'system');
    }

    return [...set];
});

function setActor(name: string): void {
    // Backend filter is by service/action only; actor filter is client-side.
    actorFilter.value = name;
}

const actorFilter = ref<string>('all');

const filteredLogs = computed(() =>
    actorFilter.value === 'all'
        ? visibleLogs.value
        : visibleLogs.value.filter(
              (log) => (log.user_name ?? 'system') === actorFilter.value,
          ),
);

function setService(id: 'all' | number): void {
    if (id === 'all') {
        applyFilters({ service_id: null });

        return;
    }

    applyFilters({ service_id: id });
}
</script>

<template>
    <Head title="Activity log" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero -->
        <div class="flex items-end justify-between gap-3">
            <div>
                <h1 class="text-[22px] font-semibold tracking-tight">
                    Activity log
                </h1>
                <p
                    class="mt-1 max-w-[640px] text-[13px] text-muted-foreground"
                >
                    Append-only audit feed. Every webhook, tool call, and admin
                    write — same source as the dashboard.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button variant="outline" size="sm" class="h-7 gap-1.5 text-xs">
                    <Calendar class="size-3.5" />Last 24h
                </Button>
                <Button variant="outline" size="sm" class="h-7 gap-1.5 text-xs">
                    <Download class="size-3.5" />Export NDJSON
                </Button>
            </div>
        </div>

        <!-- Filters -->
        <div
            class="flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card p-3"
        >
            <span
                class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                >Filter</span
            >
            <div class="flex items-center gap-1">
                <span class="text-xs text-muted-foreground">Actor</span>
                <button
                    v-for="actor in actorOptions"
                    :key="actor"
                    type="button"
                    :class="
                        cn(
                            'inline-flex h-6 items-center rounded-md px-2 text-[11.5px] font-medium transition-colors',
                            actorFilter === actor
                                ? 'bg-accent text-accent-foreground'
                                : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                        )
                    "
                    @click="setActor(actor)"
                >
                    {{ actor }}
                </button>
            </div>
            <span class="h-4 w-px bg-border" />
            <div class="flex items-center gap-1">
                <span class="text-xs text-muted-foreground">Service</span>
                <button
                    type="button"
                    :class="
                        cn(
                            'inline-flex h-6 items-center rounded-md px-2 text-[11.5px] font-medium transition-colors',
                            filters.service_id === null
                                ? 'bg-accent text-accent-foreground'
                                : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                        )
                    "
                    @click="setService('all')"
                >
                    all
                </button>
                <button
                    v-for="service in filterOptions.services"
                    :key="service.id"
                    type="button"
                    :class="
                        cn(
                            'inline-flex h-6 items-center rounded-md px-2 text-[11.5px] font-medium transition-colors',
                            filters.service_id === service.id
                                ? 'bg-accent text-accent-foreground'
                                : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                        )
                    "
                    @click="setService(service.id)"
                >
                    {{ service.type }}
                </button>
            </div>
            <div class="ml-auto flex items-center gap-2">
                <span
                    v-if="staleCount > 0"
                    class="flex items-center gap-1.5 text-xs text-accent"
                >
                    <Sparkles class="size-3.5" />
                    {{ staleCount }} new
                </span>
                <Button
                    v-if="staleCount > 0"
                    variant="ghost"
                    size="sm"
                    class="h-6 px-2 text-xs"
                    @click="refresh"
                >
                    Refresh
                </Button>
                <span
                    class="font-mono-tabular text-[11.5px] text-muted-foreground"
                >
                    {{ filteredLogs.length }} of {{ logs.meta.total }}
                </span>
            </div>
        </div>

        <!-- Log timeline -->
        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <div
                v-for="(log, i) in filteredLogs"
                :key="log.id"
                :class="[
                    'flex items-center gap-3 px-4 py-2.5 text-[13px]',
                    i < filteredLogs.length - 1 && 'border-b border-border',
                ]"
            >
                <span
                    class="font-mono-tabular w-20 shrink-0 text-[11.5px] text-fg-subtle"
                >
                    {{ formatTime(log.created_at) }}
                </span>
                <span
                    v-if="!log.user_name"
                    class="inline-flex h-5 items-center gap-1 rounded-full border border-border bg-bg-elev px-2 text-[11px] text-muted-foreground"
                >
                    <Cpu class="size-3" />system
                </span>
                <span
                    v-else
                    class="flex w-32 items-center gap-2 truncate"
                >
                    <InitialsAvatar :name="log.user_name" :size="20" />
                    <span class="truncate text-[12.5px]">{{
                        log.user_name
                    }}</span>
                </span>
                <SvcChip
                    v-if="log.service_name"
                    :id="svcId(log.service_name)"
                    :label="log.service_name"
                />
                <span
                    v-else
                    class="text-[12px] text-fg-subtle"
                    >—</span
                >
                <span
                    class="font-mono-tabular min-w-[160px] text-[12px] text-foreground"
                >
                    {{ log.action }}
                </span>
                <span
                    class="min-w-0 flex-1 truncate text-[12.5px] text-muted-foreground"
                >
                    {{ log.description }}
                </span>
                <Pill class="text-[10.5px]">{{ channelLabel(log.action) }}</Pill>
                <Pill :variant="statusVariant(log.action)" dot>ok</Pill>
            </div>
            <div
                v-if="filteredLogs.length === 0"
                class="px-4 py-12 text-center text-sm text-fg-subtle"
            >
                No activity matches these filters.
            </div>
        </div>

        <!-- Pagination -->
        <div
            v-if="logs.meta.last_page > 1"
            class="flex flex-wrap items-center justify-between gap-2"
        >
            <p class="text-sm text-muted-foreground">
                Page {{ logs.meta.current_page }} of
                {{ logs.meta.last_page }} —
                <span class="font-mono-tabular">{{ logs.meta.total }}</span>
                entries
            </p>
            <div class="flex flex-wrap gap-1">
                <Button
                    v-for="link in logs.links"
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
