<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Check,
    Filter,
    Inbox,
    RefreshCcw,
    X,
} from 'lucide-vue-next';
import { computed, onMounted } from 'vue';
import ActionRequestController from '@/actions/App/Http/Controllers/Actions/ActionRequestController';
import ActivityLogController from '@/actions/App/Http/Controllers/ActivityLogController';
import {
    InitialsAvatar,
    LiveDot,
    Pill,
    Poster,
    StatCard,
    SvcChip,
} from '@/components/mm';
import { Skeleton } from '@/components/ui/skeleton';
import { useDashboardStats } from '@/composables/useDashboardStats';
import { useRealtimeList } from '@/composables/useRealtimeList';
import { dashboard } from '@/routes';
import type { ActivityLogResource } from '@/typefinder/resources/ActivityLogResource';

type ActivityItem = ActivityLogResource;

interface ServiceItem {
    id: number;
    type: string;
    name: string;
    health: 'healthy' | 'unhealthy' | 'unknown' | string;
    version: string | null;
    latest_version: string | null;
    last_seen_at: string | null;
    is_active: boolean;
    latency_spark: number[];
    avg_latency_ms: number | null;
}

interface NowPlayingItem {
    media_title: string | null;
    series_title: string | null;
    emby_username: string | null;
    media_type: string;
    action: string;
    play_position: number | null;
    duration_ticks: number | null;
}

interface PendingApproval {
    id: number;
    type: string;
    target_service: string;
    subject_label: string;
    requested_by: string;
    trigger: string;
    created_at: string | null;
}

const props = defineProps<{
    stats: {
        activeServices: number;
        totalServices: number;
        healthyServices: number;
        recentWebhooks: number;
        pendingActions: number;
        failedActions: number;
        recentActions: number;
    };
    services: ServiceItem[];
    recentActivity: ActivityItem[];
    pendingApprovals: PendingApproval[];
    nowPlaying?: NowPlayingItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Overview', href: dashboard().url },
            { title: 'Dashboard', href: dashboard().url },
        ],
    },
});

const page = usePage();
const userName = computed(() => page.props.auth.user?.name ?? 'there');

const greeting = computed(() => {
    const h = new Date().getHours();

    if (h < 5) {
        return 'Late night';
    }

    if (h < 12) {
        return 'Good morning';
    }

    if (h < 17) {
        return 'Good afternoon';
    }

    if (h < 22) {
        return 'Good evening';
    }

    return 'Late night';
});

const { stats: liveStats, subscribe: subscribeStats } = useDashboardStats();

const { items: liveActivity, subscribe: subscribeActivity } =
    useRealtimeList<ActivityItem>({
        channel: 'activity',
        event: 'ActivityLogCreated',
        keyField: 'id',
        initial: props.recentActivity,
        cap: 10,
    });

const recentActions = computed(() => props.stats.recentActions);
const failedActions = computed(() => props.stats.failedActions);
const recentWebhooks = computed(
    () => liveStats.value?.recentWebhooks ?? props.stats.recentWebhooks,
);
const pendingActions = computed(
    () => liveStats.value?.pendingActions ?? props.stats.pendingActions,
);

const isLoadingNowPlaying = computed(() => props.nowPlaying === undefined);
const currentNowPlaying = computed(() => props.nowPlaying ?? []);

const totalServices = computed(() => props.services.length || 1);
const healthyServices = computed(
    () =>
        props.services.filter((service) => service.health === 'healthy').length,
);

// Placeholder sparkline series until service_metrics is wired.
const sparkA = [
    4, 6, 5, 8, 7, 9, 10, 8, 11, 12, 10, 13, 14, 12, 15, 16, 14, 17,
];
const sparkB = [
    12, 11, 13, 12, 14, 13, 15, 14, 13, 15, 16, 14, 15, 17, 16, 15, 18, 17,
];
const sparkC = [2, 3, 2, 4, 3, 5, 4, 3, 5, 4, 6, 5, 4, 6, 5, 7, 6, 5];

function formatRelative(iso: string | null): string {
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

function formatTicks(ticks: number | null): string {
    if (!ticks) {
        return '0:00';
    }

    const totalSec = Math.floor(ticks / 10_000_000);
    const h = Math.floor(totalSec / 3600);
    const m = Math.floor((totalSec % 3600) / 60);
    const s = totalSec % 60;

    if (h > 0) {
        return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }

    return `${m}:${String(s).padStart(2, '0')}`;
}

function progressPct(item: NowPlayingItem): number {
    if (!item.duration_ticks || !item.play_position) {
        return 0;
    }

    return Math.min(100, (item.play_position / item.duration_ticks) * 100);
}

function svcId(serviceType: string): string {
    const t = serviceType.toLowerCase();

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

    return t;
}

function actionLabel(type: string): string {
    return type.replace(/_/g, ' ');
}

function isDestructive(type: string): boolean {
    return /delete|remove|destroy/.test(type);
}

onMounted(() => {
    subscribeStats();
    subscribeActivity();
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero -->
        <div class="flex items-end justify-between">
            <div>
                <h1
                    class="text-[22px] leading-tight font-semibold tracking-tight"
                >
                    {{ greeting }}, {{ userName }}
                </h1>
                <div
                    class="mt-1 flex flex-wrap items-center gap-2 text-[13px] text-muted-foreground"
                >
                    <Pill variant="ok">
                        <LiveDot class="text-success" />
                        {{ healthyServices }} of {{ totalServices }} services
                        healthy
                    </Pill>
                    <span class="text-fg-subtle">·</span>
                    <span>{{ pendingActions }} actions awaiting approval</span>
                    <span class="text-fg-subtle">·</span>
                    <span
                        >{{ currentNowPlaying.length }}
                        {{
                            currentNowPlaying.length === 1
                                ? 'stream'
                                : 'streams'
                        }}
                        active</span
                    >
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md border border-border bg-card px-2 text-xs font-medium text-foreground transition-colors hover:bg-bg-hover"
                >
                    <RefreshCcw class="size-3.5" />Refresh
                </button>
                <Link
                    :href="ActionRequestController.index.url()"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md bg-accent px-2 text-xs font-medium text-accent-foreground transition-colors hover:bg-accent/90"
                >
                    <Inbox class="size-3.5" />Review queue
                </Link>
            </div>
        </div>

        <!-- Stat Cards -->
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <StatCard
                label="Services online"
                :value="`${healthyServices} / ${totalServices}`"
                :hint="`${stats.activeServices} active connections`"
                :spark="sparkB"
            />
            <StatCard
                label="Webhooks · 24h"
                :value="recentWebhooks.toString()"
                hint="ingest stream"
                :spark="sparkA"
            />
            <StatCard
                label="Actions · 24h"
                :value="recentActions.toString()"
                :hint="`${pendingActions} pending · ${failedActions} failed`"
                :spark="sparkC"
            >
                <template v-if="failedActions > 0" #accent>
                    <Pill variant="warn"
                        >{{ failedActions }}
                        {{ failedActions === 1 ? 'failed' : 'failed' }}</Pill
                    >
                </template>
            </StatCard>
            <StatCard
                label="Streams · now"
                :value="currentNowPlaying.length.toString()"
                hint="live Emby sessions"
                :spark="sparkB"
            />
        </div>

        <!-- Live activity + Now playing -->
        <div class="grid gap-4 lg:grid-cols-[1.5fr_1fr]">
            <!-- Live activity -->
            <div
                class="overflow-hidden rounded-xl border border-border bg-card"
            >
                <div
                    class="flex items-center justify-between border-b border-border px-4 py-3"
                >
                    <span
                        class="flex items-center gap-2 text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                    >
                        <LiveDot class="text-accent" />
                        Live activity
                    </span>
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            class="inline-flex h-7 items-center gap-1.5 rounded-md px-2 text-xs font-medium text-muted-foreground transition-colors hover:bg-bg-hover hover:text-foreground"
                        >
                            <Filter class="size-3.5" />All services
                        </button>
                        <Link
                            :href="ActivityLogController().url"
                            class="inline-flex h-7 items-center rounded-md px-2 text-xs font-medium text-muted-foreground transition-colors hover:bg-bg-hover hover:text-foreground"
                        >
                            View log →
                        </Link>
                    </div>
                </div>
                <div
                    v-if="liveActivity.length === 0"
                    class="flex flex-col items-center gap-2 py-10 text-fg-subtle"
                >
                    <Inbox class="size-5" />
                    <span class="text-sm">No recent activity</span>
                </div>
                <div v-else>
                    <div
                        v-for="row in liveActivity"
                        :key="row.id"
                        class="flex items-center gap-3 border-b border-border px-4 py-2.5 last:border-b-0"
                    >
                        <span
                            class="font-mono-tabular w-12 shrink-0 text-[11.5px] text-fg-subtle"
                        >
                            {{ formatRelative(row.created_at) }}
                        </span>
                        <span
                            class="size-1.5 shrink-0 rounded-full bg-info"
                            aria-hidden="true"
                        />
                        <SvcChip
                            v-if="row.service_name"
                            :id="svcId(row.service_name)"
                            :label="row.service_name"
                        />
                        <span
                            class="min-w-0 flex-1 truncate text-[13px] text-foreground"
                        >
                            {{ row.description }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Now playing -->
            <div
                class="overflow-hidden rounded-xl border border-border bg-card"
            >
                <div
                    class="flex items-center justify-between border-b border-border px-4 py-3"
                >
                    <span
                        class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                    >
                        Now playing
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ currentNowPlaying.length }}
                        {{
                            currentNowPlaying.length === 1
                                ? 'session'
                                : 'sessions'
                        }}
                    </span>
                </div>

                <div
                    v-if="isLoadingNowPlaying"
                    class="flex flex-col gap-2 p-3"
                    data-testid="now-playing-skeleton"
                >
                    <Skeleton
                        v-for="i in 2"
                        :key="`np-skel-${i}`"
                        class="h-20 w-full rounded-md"
                    />
                </div>
                <div
                    v-else-if="currentNowPlaying.length === 0"
                    class="flex flex-col items-center gap-2 py-10 text-fg-subtle"
                >
                    <span class="text-sm">No active playback sessions</span>
                </div>
                <div v-else class="flex flex-col gap-2.5 p-3">
                    <div
                        v-for="(item, index) in currentNowPlaying"
                        :key="`np-${item.emby_username ?? 'u'}-${index}`"
                        class="flex items-center gap-3 rounded-lg border border-border p-3"
                    >
                        <Poster :hint="item.media_title ?? 'media'" size="md" />
                        <div class="min-w-0 flex-1">
                            <div class="mb-1 flex items-center gap-2">
                                <InitialsAvatar
                                    :name="item.emby_username ?? '?'"
                                    :size="20"
                                />
                                <span class="text-xs text-muted-foreground">{{
                                    item.emby_username ?? 'unknown'
                                }}</span>
                                <Pill variant="ok" class="ml-auto">
                                    <LiveDot class="text-success" />
                                    Live
                                </Pill>
                            </div>
                            <div
                                class="truncate text-sm leading-tight font-semibold"
                            >
                                {{ item.media_title ?? 'Unknown' }}
                            </div>
                            <div
                                v-if="item.series_title"
                                class="mb-2 truncate text-xs text-muted-foreground"
                            >
                                {{ item.series_title }}
                            </div>
                            <div
                                class="h-1 overflow-hidden rounded-full bg-bg-elev"
                            >
                                <div
                                    class="h-full rounded-full bg-accent"
                                    :style="{
                                        width: `${progressPct(item)}%`,
                                    }"
                                />
                            </div>
                            <div
                                class="font-mono-tabular mt-1 flex justify-between text-[11px] text-fg-subtle"
                            >
                                <span>{{
                                    formatTicks(item.play_position)
                                }}</span>
                                <span>{{
                                    formatTicks(item.duration_ticks)
                                }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Service health + pending approvals -->
        <div class="grid gap-4 lg:grid-cols-2">
            <!-- Service health mini -->
            <div
                class="overflow-hidden rounded-xl border border-border bg-card"
            >
                <div
                    class="flex items-center justify-between border-b border-border px-4 py-3"
                >
                    <span
                        class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                        >Service health</span
                    >
                    <span class="text-xs text-muted-foreground"
                        >{{ services.length }} connections</span
                    >
                </div>
                <div
                    v-if="services.length === 0"
                    class="px-4 py-6 text-sm text-fg-subtle"
                >
                    No service connections configured.
                </div>
                <div v-else>
                    <div
                        v-for="service in services"
                        :key="service.id"
                        class="flex items-center gap-3 border-b border-border px-4 py-2.5 last:border-b-0"
                    >
                        <span
                            v-if="service.health === 'healthy'"
                            class="text-success"
                            ><Check class="size-4"
                        /></span>
                        <span
                            v-else-if="service.health === 'unhealthy'"
                            class="text-destructive"
                            ><X class="size-4"
                        /></span>
                        <span v-else class="text-warning"
                            ><AlertTriangle class="size-4"
                        /></span>
                        <span class="text-[13px] font-medium">{{
                            service.name
                        }}</span>
                        <SvcChip
                            :id="svcId(service.type)"
                            :label="service.type"
                        />
                        <span
                            v-if="service.avg_latency_ms !== null"
                            class="font-mono-tabular ml-auto text-[11px] text-fg-subtle"
                        >
                            {{ service.avg_latency_ms }}ms
                        </span>
                        <span
                            v-else
                            class="font-mono-tabular ml-auto text-[11px] text-fg-subtle"
                        >
                            {{ formatRelative(service.last_seen_at) }}
                        </span>
                        <Pill
                            v-if="
                                service.latest_version &&
                                service.version &&
                                service.latest_version !== service.version
                            "
                            variant="warn"
                            >update</Pill
                        >
                    </div>
                </div>
            </div>

            <!-- Pending approvals -->
            <div
                class="overflow-hidden rounded-xl border border-border bg-card"
            >
                <div
                    class="flex items-center justify-between border-b border-border px-4 py-3"
                >
                    <span
                        class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                        >Pending approvals</span
                    >
                    <Pill v-if="pendingActions > 0" variant="warn">{{
                        pendingActions
                    }}</Pill>
                </div>
                <div
                    v-if="pendingApprovals.length === 0"
                    class="px-4 py-6 text-sm text-fg-subtle"
                >
                    Queue is empty.
                </div>
                <div v-else>
                    <div
                        v-for="action in pendingApprovals"
                        :key="action.id"
                        class="border-b border-border px-4 py-3 last:border-b-0"
                    >
                        <div
                            class="font-mono-tabular flex items-center justify-between text-[11px] text-fg-subtle"
                        >
                            <span>act_{{ action.id }}</span>
                            <span>{{ formatRelative(action.created_at) }}</span>
                        </div>
                        <div class="mt-1 text-sm font-medium">
                            <span
                                :class="
                                    isDestructive(action.type)
                                        ? 'text-destructive'
                                        : 'text-info'
                                "
                                >{{ actionLabel(action.type) }}</span
                            >
                            <span class="text-fg-subtle"> · </span>
                            <span>{{ action.subject_label }}</span>
                        </div>
                        <div class="mt-1.5 flex items-center justify-between">
                            <span class="text-xs text-muted-foreground">
                                via {{ action.trigger }} · by
                                {{ action.requested_by }}
                            </span>
                            <Link
                                :href="ActionRequestController.index.url()"
                                class="inline-flex h-6 items-center rounded-md border border-destructive/35 px-2 text-xs font-medium text-destructive transition-colors hover:bg-destructive/10"
                            >
                                Review
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
