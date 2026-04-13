<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Activity, Clock, Radio, Server, Webhook } from 'lucide-vue-next';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { useDashboardStats } from '@/composables/useDashboardStats';
import { useEmbyActivity } from '@/composables/useEmbyActivity';
import { useNotifications } from '@/composables/useNotifications';
import { dashboard } from '@/routes';

interface ActivityItem {
    id: number;
    action: string;
    description: string;
    user_name: string | null;
    service_name: string | null;
    service_type: string | null;
    created_at: string;
}

interface WebhookEventItem {
    id: number;
    event_type: string;
    service_name: string | null;
    service_type: string | null;
    processed: boolean;
    created_at: string;
}

interface NowPlayingItem {
    id: number;
    media_type: string;
    media_title: string;
    series_title: string | null;
    action: string;
    emby_username: string | null;
}

const props = defineProps<{
    stats: {
        activeServices: number;
        totalServices: number;
        recentWebhooks: number;
        pendingActions: number;
    };
    recentActivity: ActivityItem[];
    recentWebhookEvents: WebhookEventItem[];
    nowPlaying?: NowPlayingItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const { stats: liveStats, subscribe: subscribeStats } = useDashboardStats();
const { nowPlaying: liveNowPlaying, subscribe: subscribeEmby } = useEmbyActivity();
const { subscribe: subscribeNotifications } = useNotifications();

const activeServices = computed(() => liveStats.value?.activeServices ?? props.stats.activeServices);
const totalServices = computed(() => liveStats.value?.totalServices ?? props.stats.totalServices);
const recentWebhooks = computed(() => liveStats.value?.recentWebhooks ?? props.stats.recentWebhooks);
const pendingActions = computed(() => liveStats.value?.pendingActions ?? props.stats.pendingActions);

const currentNowPlaying = computed(() => {
    if (liveNowPlaying.value.length > 0) {
        return liveNowPlaying.value;
    }

    return props.nowPlaying ?? [];
});

function formatTime(isoString: string): string {
    const date = new Date(isoString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);

    if (diffMins < 1) {
        return 'Just now';
    }

    if (diffMins < 60) {
        return `${diffMins}m ago`;
    }

    const diffHours = Math.floor(diffMins / 60);

    if (diffHours < 24) {
        return `${diffHours}h ago`;
    }

    const diffDays = Math.floor(diffHours / 24);

    return `${diffDays}d ago`;
}

onMounted(() => {
    subscribeStats();
    subscribeEmby();
    subscribeNotifications();
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="space-y-6 p-6">
        <!-- Stat Cards -->
        <div class="grid gap-4 md:grid-cols-3">
            <Card>
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <CardDescription>Active Services</CardDescription>
                        <Server class="size-4 text-muted-foreground" />
                    </div>
                    <CardTitle class="text-2xl tabular-nums">
                        {{ activeServices }}
                        <span class="text-sm font-normal text-muted-foreground">/ {{ totalServices }}</span>
                    </CardTitle>
                </CardHeader>
            </Card>

            <Card>
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <CardDescription>Recent Webhooks</CardDescription>
                        <Webhook class="size-4 text-muted-foreground" />
                    </div>
                    <CardTitle class="text-2xl tabular-nums">
                        {{ recentWebhooks }}
                        <span class="text-sm font-normal text-muted-foreground">Last 24h</span>
                    </CardTitle>
                </CardHeader>
            </Card>

            <Card>
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <CardDescription>Pending Actions</CardDescription>
                        <Clock class="size-4 text-muted-foreground" />
                    </div>
                    <CardTitle class="text-2xl tabular-nums">
                        {{ pendingActions }}
                    </CardTitle>
                </CardHeader>
            </Card>
        </div>

        <!-- Activity + Now Playing -->
        <div class="grid gap-4 lg:grid-cols-5">
            <!-- Recent Activity -->
            <Card class="lg:col-span-3">
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <Activity class="size-4 text-muted-foreground" />
                        <CardTitle>Recent Activity</CardTitle>
                    </div>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="recentActivity.length === 0 && recentWebhookEvents.length === 0"
                        class="flex flex-col items-center justify-center py-8 text-center"
                    >
                        <Activity class="mb-2 size-8 text-muted-foreground/50" />
                        <p class="text-sm text-muted-foreground">No recent activity</p>
                    </div>

                    <div v-else class="space-y-4">
                        <div
                            v-for="item in recentActivity"
                            :key="`activity-${item.id}`"
                            class="flex items-start justify-between gap-4"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium leading-none">{{ item.description }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    <span v-if="item.user_name">{{ item.user_name }}</span>
                                    <span v-if="item.user_name && item.service_name"> &middot; </span>
                                    <span v-if="item.service_name">{{ item.service_name }}</span>
                                </p>
                            </div>
                            <span class="shrink-0 text-xs text-muted-foreground">{{ formatTime(item.created_at) }}</span>
                        </div>

                        <div v-if="recentWebhookEvents.length > 0" class="border-t pt-4">
                            <p class="mb-3 text-xs font-medium uppercase tracking-wider text-muted-foreground">Recent Webhooks</p>
                            <div
                                v-for="event in recentWebhookEvents"
                                :key="`webhook-${event.id}`"
                                class="mb-3 flex items-start justify-between gap-4 last:mb-0"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <p class="text-sm font-medium leading-none">{{ event.event_type }}</p>
                                        <Badge variant="outline" class="text-xs">
                                            {{ event.processed ? 'Processed' : 'Pending' }}
                                        </Badge>
                                    </div>
                                    <p v-if="event.service_name" class="mt-1 text-xs text-muted-foreground">
                                        {{ event.service_name }}
                                    </p>
                                </div>
                                <span class="shrink-0 text-xs text-muted-foreground">{{ formatTime(event.created_at) }}</span>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Now Playing -->
            <Card class="lg:col-span-2">
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <Radio class="size-4 text-muted-foreground" />
                        <CardTitle>Now Playing</CardTitle>
                    </div>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="currentNowPlaying.length === 0"
                        class="flex flex-col items-center justify-center py-8 text-center"
                    >
                        <Radio class="mb-2 size-8 text-muted-foreground/50" />
                        <p class="text-sm text-muted-foreground">No active playback sessions</p>
                    </div>

                    <div v-else class="space-y-4">
                        <div v-for="item in currentNowPlaying" :key="item.id" class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium leading-none">{{ item.media_title }}</p>
                                <p v-if="item.series_title" class="mt-1 text-xs text-muted-foreground">
                                    {{ item.series_title }}
                                </p>
                                <p v-if="item.emby_username" class="mt-1 text-xs text-muted-foreground">
                                    {{ item.emby_username }}
                                </p>
                            </div>
                            <Badge variant="outline" class="shrink-0 text-xs">{{ item.media_type }}</Badge>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
