<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Calendar, Download, Sparkles } from 'lucide-vue-next';
import { computed, onMounted, watch } from 'vue';
import WatchHistoryController from '@/actions/App/Http/Controllers/Emby/WatchHistoryController';
import { InitialsAvatar, Pill, StatCard, SvcChip } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useRealtimeList } from '@/composables/useRealtimeList';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { EmbyActivityResource } from '@/typefinder/resources/EmbyActivityResource';

type Activity = EmbyActivityResource;

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

const props = defineProps<{
    activities: {
        data: Activity[];
        links: PaginatorLink[];
        meta: PaginatorMeta;
    };
    filters: { media_type: string; since: number };
    filterOptions: { rangeDays: number[] };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Live', href: dashboard().url },
            { title: 'Watch history', href: WatchHistoryController.index.url() },
        ],
    },
});

const page = usePage();
const isViewer = computed(() => {
    const role = page.props.auth.user?.role;

    if (!role) {
        return false;
    }

    const value = typeof role === 'string' ? role : role.value;

    return value === 'viewer';
});

const hasFilter = computed(() => props.filters.media_type !== '');
const onFirstPage = computed(() => props.activities.meta.current_page === 1);
const merge = computed(
    () => !hasFilter.value && onFirstPage.value && !isViewer.value,
);

const {
    items: liveActivities,
    staleCount,
    pause,
    resume,
    subscribe,
} = useRealtimeList<Activity>({
    channel: 'emby.activity',
    event: 'EmbyPlaybackUpdated',
    keyField: 'id',
    initial: props.activities.data,
    cap: props.activities.meta.per_page,
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

const visibleActivities = computed(() =>
    merge.value ? liveActivities.value : props.activities.data,
);

function refresh(): void {
    router.reload({ only: ['activities'], onSuccess: () => resume() });
}

function formatTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const ms = Date.now() - new Date(iso).getTime();
    const m = Math.floor(ms / 60_000);

    if (m < 1) {
        return 'just now';
    }

    if (m < 60) {
        return `${m}m ago`;
    }

    const h = Math.floor(m / 60);

    if (h < 24) {
        return `${h}h ago`;
    }

    return `${Math.floor(h / 24)}d ago`;
}

function ticksToHours(ticks: number | null): number {
    if (!ticks) {
        return 0;
    }

    return ticks / 10_000_000 / 3600;
}

function ticksToText(ticks: number | null): string {
    if (!ticks) {
        return '0m';
    }

    const total = Math.floor(ticks / 10_000_000);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);

    if (h > 0) {
        return `${h}h ${m}m`;
    }

    return `${m}m`;
}

function completionPct(activity: Activity): number {
    const pos = activity.play_position ?? 0;
    const dur = activity.duration_ticks ?? 0;

    if (!dur) {
        return 0;
    }

    return Math.min(100, (pos / dur) * 100);
}

const totals = computed(() => {
    const items = visibleActivities.value;
    let totalTicks = 0;
    let completed = 0;
    const userTicks = new Map<string, number>();

    for (const a of items) {
        const dur = a.duration_ticks ?? 0;
        totalTicks += dur;

        if (completionPct(a) >= 90) {
            completed++;
        }

        const u = a.emby_username ?? 'unknown';
        userTicks.set(u, (userTicks.get(u) ?? 0) + dur);
    }

    let topUser: { name: string; ticks: number; sessions: number } | null =
        null;

    for (const [name, ticks] of userTicks) {
        if (!topUser || ticks > topUser.ticks) {
            topUser = {
                name,
                ticks,
                sessions: items.filter(
                    (a) => (a.emby_username ?? 'unknown') === name,
                ).length,
            };
        }
    }

    return {
        hours: ticksToHours(totalTicks),
        sessions: items.length,
        completionRate:
            items.length > 0 ? Math.round((completed / items.length) * 100) : 0,
        topUser,
    };
});

function applyFilters(next: { media_type?: string; since?: number }) {
    const merged = {
        media_type:
            'media_type' in next
                ? (next.media_type ?? '')
                : props.filters.media_type,
        since: 'since' in next ? (next.since ?? 7) : props.filters.since,
    };

    const query: Record<string, string | number> = {};

    if (merged.media_type) {
        query.media_type = merged.media_type;
    }

    if (merged.since && merged.since !== 7) {
        query.since = merged.since;
    }

    router.get(WatchHistoryController.index.url(), query, {
        preserveScroll: true,
        replace: true,
    });
}

function onMediaTypeChange(value: unknown) {
    const v = typeof value === 'string' ? value : '';
    applyFilters({ media_type: v === 'all' ? '' : v });
}

function setRange(days: number) {
    applyFilters({ since: days });
}

function rangeLabel(days: number): string {
    if (days === 1) {
        return '24h';
    }

    return `${days}d`;
}

const exportUrl = computed(() => {
    const params = new URLSearchParams();

    if (props.filters.media_type) {
        params.set('media_type', props.filters.media_type);
    }

    if (props.filters.since !== 7) {
        params.set('since', String(props.filters.since));
    }

    const qs = params.toString();
    const base = WatchHistoryController.exportMethod.url();

    return qs ? `${base}?${qs}` : base;
});

function goToPage(url: string | null) {
    if (!url) {
        return;
    }

    router.get(url, {}, { preserveState: true, preserveScroll: true });
}

function currentFilter(): string {
    return props.filters.media_type === '' ? 'all' : props.filters.media_type;
}
</script>

<template>
    <Head title="Watch history" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero -->
        <div class="flex items-end justify-between gap-3">
            <div>
                <div class="mb-1.5 flex items-center gap-2">
                    <SvcChip id="emby" />
                    <span class="text-fg-subtle">/</span>
                    <span class="text-[13px] text-muted-foreground"
                        >Watch history</span
                    >
                </div>
                <h1
                    class="text-[22px] leading-tight font-semibold tracking-tight"
                >
                    Watch history
                </h1>
                <p class="mt-1 text-[13px] text-muted-foreground">
                    {{ activities.meta.total }} entries · recorded from Emby
                    playback webhooks
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Select
                    :default-value="currentFilter()"
                    @update:model-value="onMediaTypeChange"
                >
                    <SelectTrigger class="h-7 w-32 text-xs">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All types</SelectItem>
                        <SelectItem value="movie">Movie</SelectItem>
                        <SelectItem value="episode">Episode</SelectItem>
                    </SelectContent>
                </Select>
                <div
                    class="inline-flex h-7 items-center rounded-md border border-border bg-card p-0.5"
                >
                    <Calendar class="ml-1.5 size-3.5 text-muted-foreground" />
                    <button
                        v-for="days in filterOptions.rangeDays"
                        :key="days"
                        type="button"
                        :class="
                            cn(
                                'inline-flex h-6 items-center rounded-[4px] px-2 text-[11.5px] font-medium transition-colors',
                                filters.since === days
                                    ? 'bg-accent text-accent-foreground'
                                    : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                            )
                        "
                        @click="setRange(days)"
                    >
                        {{ rangeLabel(days) }}
                    </button>
                </div>
                <a
                    :href="exportUrl"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md border border-border bg-card px-2 text-xs font-medium text-foreground transition-colors hover:bg-bg-hover"
                >
                    <Download class="size-3.5" />Export CSV
                </a>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <StatCard
                label="Total watch time"
                :value="`${totals.hours.toFixed(1)}h`"
                :hint="`across ${totals.sessions} sessions`"
            />
            <StatCard
                label="Sessions"
                :value="totals.sessions"
                hint="visible in this view"
            />
            <StatCard
                label="Completion rate"
                :value="`${totals.completionRate}%`"
                hint="≥90% counted as complete"
            />
            <div
                class="flex min-h-[110px] flex-col gap-2.5 rounded-xl border border-border bg-card p-5"
            >
                <span
                    class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                    >Top user</span
                >
                <div v-if="totals.topUser" class="flex items-center gap-2.5">
                    <InitialsAvatar :name="totals.topUser.name" :size="32" />
                    <div>
                        <div class="text-[15px] font-semibold">
                            {{ totals.topUser.name }}
                        </div>
                        <div
                            class="font-mono-tabular text-[11.5px] text-muted-foreground"
                        >
                            {{ ticksToText(totals.topUser.ticks) }} ·
                            {{ totals.topUser.sessions }} sessions
                        </div>
                    </div>
                </div>
                <div v-else class="text-sm text-fg-subtle">No data</div>
            </div>
        </div>

        <!-- Stale notice -->
        <div
            v-if="staleCount > 0"
            class="flex items-center justify-between rounded-md border border-accent/40 bg-accent/10 px-3 py-2 text-sm"
        >
            <span class="flex items-center gap-2 text-accent">
                <Sparkles class="size-4" />
                {{ staleCount }} new
                {{ staleCount === 1 ? 'entry' : 'entries' }} arrived.
            </span>
            <Button size="sm" variant="ghost" @click="refresh">Refresh</Button>
        </div>

        <!-- Table -->
        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            v-for="h in [
                                'Started',
                                'User',
                                'Title',
                                'Watched',
                                'Completion',
                                'Type',
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
                        v-for="activity in visibleActivities"
                        :key="activity.id"
                        class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                    >
                        <td
                            class="font-mono-tabular px-3 py-2.5 text-[11.5px] whitespace-nowrap text-fg-subtle"
                        >
                            {{ formatTime(activity.created_at) }}
                        </td>
                        <td class="px-3 py-2.5">
                            <span class="flex items-center gap-2">
                                <InitialsAvatar
                                    :name="activity.emby_username ?? '?'"
                                    :size="20"
                                />
                                <span>{{ activity.emby_username ?? '—' }}</span>
                            </span>
                        </td>
                        <td class="px-3 py-2.5">
                            <div class="font-medium">
                                {{ activity.media_title ?? '—' }}
                            </div>
                            <div
                                v-if="activity.series_title"
                                class="text-[11.5px] text-muted-foreground"
                            >
                                {{ activity.series_title }}
                            </div>
                        </td>
                        <td class="font-mono-tabular px-3 py-2.5 text-[12px]">
                            {{ ticksToText(activity.duration_ticks) }}
                        </td>
                        <td class="px-3 py-2.5">
                            <div class="flex items-center gap-2">
                                <div
                                    class="h-1 w-20 overflow-hidden rounded-full bg-bg-elev"
                                >
                                    <div
                                        class="h-full rounded-full"
                                        :class="
                                            completionPct(activity) < 50
                                                ? 'bg-warning'
                                                : 'bg-accent'
                                        "
                                        :style="{
                                            width: `${completionPct(activity)}%`,
                                        }"
                                    />
                                </div>
                                <span
                                    class="font-mono-tabular w-10 text-[11.5px]"
                                    >{{
                                        Math.round(completionPct(activity))
                                    }}%</span
                                >
                            </div>
                        </td>
                        <td class="px-3 py-2.5">
                            <Pill v-if="activity.media_type">{{
                                activity.media_type
                            }}</Pill>
                            <span v-else class="text-fg-subtle">—</span>
                        </td>
                    </tr>
                    <tr v-if="visibleActivities.length === 0">
                        <td
                            colspan="6"
                            class="px-3 py-8 text-center text-sm text-fg-subtle"
                        >
                            No activity yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div
            v-if="activities.links.length > 3"
            class="flex flex-wrap items-center gap-2"
        >
            <Button
                v-for="(link, index) in activities.links"
                :key="index"
                variant="outline"
                size="sm"
                :disabled="!link.url"
                :class="link.active ? 'bg-accent text-accent-foreground' : ''"
                @click="goToPage(link.url)"
            >
                <span v-html="link.label" />
            </Button>
        </div>
    </div>
</template>
