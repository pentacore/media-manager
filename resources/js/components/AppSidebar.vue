<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    BarChart3,
    Bot,
    Brain,
    ChartLine,
    Clock,
    DollarSign,
    Download,
    Film,
    Heart,
    HeartPulse,
    Inbox,
    LayoutGrid,
    Link as LinkIcon,
    MessageSquare,
    Play,
    ScrollText,
    Search,
    Shield,
    Sparkles,
    Sprout,
    Tv,
    Users,
    ListTodo,
    Webhook as WebhookIcon,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watchEffect } from 'vue';
import ActionRequestController from '@/actions/App/Http/Controllers/Actions/ActionRequestController';
import ActionTypeConfigController from '@/actions/App/Http/Controllers/Actions/ActionTypeConfigController';
import ActivityLogController from '@/actions/App/Http/Controllers/ActivityLogController';
import AiConversationController from '@/actions/App/Http/Controllers/Admin/AiConversationController';
import AiModelPriceController from '@/actions/App/Http/Controllers/Admin/AiModelPriceController';
import AiSettingsController from '@/actions/App/Http/Controllers/Admin/AiSettingsController';
import AiUsageController from '@/actions/App/Http/Controllers/Admin/AiUsageController';
import DecisionAgentSettingsController from '@/actions/App/Http/Controllers/Admin/DecisionAgentSettingsController';
import JobsController from '@/actions/App/Http/Controllers/Admin/JobsController';
import ServiceConnectionController from '@/actions/App/Http/Controllers/Admin/ServiceConnectionController';
import AdminStatisticsController from '@/actions/App/Http/Controllers/Admin/StatisticsController';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import WebhookLogController from '@/actions/App/Http/Controllers/Admin/WebhookLogController';
import AIChatController from '@/actions/App/Http/Controllers/AI/ChatController';
import NowPlayingController from '@/actions/App/Http/Controllers/Emby/NowPlayingController';
import WatchHistoryController from '@/actions/App/Http/Controllers/Emby/WatchHistoryController';
import LibraryActivityController from '@/actions/App/Http/Controllers/Library/ActivityController';
import AnimeController from '@/actions/App/Http/Controllers/Media/AnimeController';
import MovieController from '@/actions/App/Http/Controllers/Media/MovieController';
import RequestController from '@/actions/App/Http/Controllers/Media/RequestController';
import SearchController from '@/actions/App/Http/Controllers/Media/SearchController';
import SeriesController from '@/actions/App/Http/Controllers/Media/SeriesController';
import ServiceHealthController from '@/actions/App/Http/Controllers/Monitoring/ServiceHealthController';
import SabnzbdQueueController from '@/actions/App/Http/Controllers/Sabnzbd/QueueController';
import StatisticsController from '@/actions/App/Http/Controllers/StatisticsController';
import AppLogo from '@/components/AppLogo.vue';
import AppVersion from '@/components/AppVersion.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useAiChat } from '@/composables/useAiChat';
import type { ChannelLease } from '@/composables/useWebSocket';
import { useWebSocket } from '@/composables/useWebSocket';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage();

const isAdmin = computed(() => {
    const role = page.props.auth.user?.role;

    if (!role) {
        return false;
    }

    const value = typeof role === 'string' ? role : role.value;

    return value === 'admin';
});

const aiEnabled = computed(() =>
    Boolean(
        (page.props as unknown as { ai?: { enabled?: boolean } }).ai?.enabled,
    ),
);

interface NavCounts {
    pendingActions?: number;
    activeSessions?: number;
    libraryIntervention?: number;
    sabnzbdDownloads?: { queued: number; completed: number };
}

const initialNav = (page.props as unknown as { nav?: NavCounts }).nav;

const pendingActions = ref(initialNav?.pendingActions ?? 0);
const activeSessions = ref(initialNav?.activeSessions ?? 0);
const libraryIntervention = ref(initialNav?.libraryIntervention ?? 0);
const sabnzbdQueued = ref(initialNav?.sabnzbdDownloads?.queued ?? 0);
const sabnzbdCompleted = ref(initialNav?.sabnzbdDownloads?.completed ?? 0);
const recentSessionIds = new Set<number>();

const { acquirePrivateChannel } = useWebSocket();
const channelLeases: ChannelLease[] = [];
const { openChat } = useAiChat();

let activitySessionTimer: ReturnType<typeof setInterval> | null = null;
const sessionExpiryMs = 10 * 60 * 1000;
const sessionTimestamps = new Map<number, number>();

function pruneStaleSessions(): void {
    const cutoff = Date.now() - sessionExpiryMs;
    let expired = 0;

    for (const [id, ts] of sessionTimestamps) {
        if (ts < cutoff) {
            sessionTimestamps.delete(id);
            recentSessionIds.delete(id);
            expired += 1;
        }
    }

    // Subtract only what expired locally. The counter is seeded from the
    // server snapshot, which includes sessions this tab never saw an event
    // for — overwriting with the locally-observed map size zeroed the badge
    // within a minute of every page load.
    if (expired > 0) {
        activeSessions.value = Math.max(0, activeSessions.value - expired);
    }
}

interface PlaybackPayload {
    id: number;
    action: string;
}

interface ActionRequestCreatedPayload {
    id: number;
    requires_approval: boolean;
    status: string;
}

interface ActionRequestStatusPayload {
    id: number;
    status: string;
}

const TERMINAL_STATUSES = new Set(['completed', 'failed', 'rejected']);
// Track which action ids we've already counted as pending so an
// out-of-order Created/StatusChanged sequence doesn't double-count.
const pendingIds = new Set<number>();

// Re-sync all counters whenever a fresh `nav` snapshot arrives (every
// navigation / partial reload shares it). The local ID sets only track
// deltas observed since the previous snapshot, so they are cleared here —
// keeping them would double-count events already baked into the server
// numbers.
watchEffect(() => {
    const nav = (page.props as unknown as { nav?: NavCounts }).nav;

    if (nav) {
        pendingActions.value = nav.pendingActions ?? 0;
        activeSessions.value = nav.activeSessions ?? 0;
        libraryIntervention.value = nav.libraryIntervention ?? 0;
        sabnzbdQueued.value = nav.sabnzbdDownloads?.queued ?? 0;
        sabnzbdCompleted.value = nav.sabnzbdDownloads?.completed ?? 0;
        recentSessionIds.clear();
        sessionTimestamps.clear();
        pendingIds.clear();
    }
});

onMounted(() => {
    channelLeases.push(
        acquirePrivateChannel('emby.activity').listen(
            '.EmbyPlaybackUpdated',
            (event: PlaybackPayload) => {
                if (event.action === 'played') {
                    if (!recentSessionIds.has(event.id)) {
                        recentSessionIds.add(event.id);
                        activeSessions.value += 1;
                    }

                    sessionTimestamps.set(event.id, Date.now());
                } else {
                    if (recentSessionIds.has(event.id)) {
                        recentSessionIds.delete(event.id);
                        sessionTimestamps.delete(event.id);
                        activeSessions.value = Math.max(
                            0,
                            activeSessions.value - 1,
                        );
                    }
                }
            },
        ),
    );

    channelLeases.push(
        acquirePrivateChannel('members.actions')
            .listen(
                '.ActionRequestCreated',
                (event: ActionRequestCreatedPayload) => {
                    if (
                        event.status === 'pending' &&
                        !pendingIds.has(event.id)
                    ) {
                        pendingIds.add(event.id);
                        pendingActions.value += 1;
                    }
                },
            )
            .listen(
                '.ActionRequestStatusChanged',
                (event: ActionRequestStatusPayload) => {
                    if (
                        TERMINAL_STATUSES.has(event.status) ||
                        event.status === 'approved' ||
                        event.status === 'executing'
                    ) {
                        if (pendingIds.has(event.id)) {
                            pendingIds.delete(event.id);
                            pendingActions.value = Math.max(
                                0,
                                pendingActions.value - 1,
                            );
                        }
                    }
                },
            ),
    );

    channelLeases.push(
        acquirePrivateChannel('dashboard')
            .listen(
                '.LibraryInterventionChanged',
                (event: { count: number }) => {
                    libraryIntervention.value = event.count;
                },
            )
            .listen(
                '.SabnzbdDownloadCountsChanged',
                (event: { queued: number; completed: number }) => {
                    sabnzbdQueued.value = event.queued;
                    sabnzbdCompleted.value = event.completed;
                },
            ),
    );

    activitySessionTimer = setInterval(pruneStaleSessions, 60_000);
});

onUnmounted(() => {
    channelLeases.splice(0).forEach((lease) => lease.release());

    if (activitySessionTimer) {
        clearInterval(activitySessionTimer);
        activitySessionTimer = null;
    }
});

const overviewNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Action Queue',
        href: ActionRequestController.index.url(),
        icon: Inbox,
        badge: () => pendingActions.value,
    },
    {
        title: 'Statistics',
        href: StatisticsController().url,
        icon: ChartLine,
    },
]);

const mediaNavItems = computed<NavItem[]>(() => [
    {
        title: 'TV Series',
        href: SeriesController.index.url(),
        icon: Tv,
    },
    {
        title: 'Movies',
        href: MovieController.index.url(),
        icon: Film,
    },
    {
        title: 'Requests',
        href: RequestController.index.url(),
        icon: Heart,
    },
    {
        title: 'Seasonal Anime',
        href: AnimeController.index.url(),
        icon: Sprout,
    },
    {
        title: 'Search',
        href: SearchController.index.url(),
        icon: Search,
    },
    {
        title: 'Downloads',
        href: SabnzbdQueueController.index.url(),
        icon: Download,
        // Show "queued + still-in-history" so the badge surfaces both
        // active downloads and stuck post-processing rows. SAB prunes
        // imported items itself, so anything here means "needs a look".
        badge: () => sabnzbdQueued.value + sabnzbdCompleted.value,
    },
    {
        title: 'Library activity',
        href: LibraryActivityController.queue.url(),
        icon: Activity,
        badge: () => libraryIntervention.value,
    },
]);

const liveNavItems = computed<NavItem[]>(() => [
    {
        title: 'Now Playing',
        href: NowPlayingController().url,
        icon: Play,
        badge: () => activeSessions.value,
    },
    {
        title: 'Watch history',
        href: WatchHistoryController.index.url(),
        icon: Clock,
    },
    {
        title: 'Service Health',
        href: ServiceHealthController.index.url(),
        icon: HeartPulse,
    },
    {
        title: 'Activity log',
        href: ActivityLogController.index.url(),
        icon: ScrollText,
    },
]);

const aiNavItems: NavItem[] = [
    {
        title: 'AI Assistant',
        href: AIChatController.index.url(),
        icon: Sparkles,
    },
];

const aiAdminNavItems: NavItem[] = [
    {
        title: 'AI Settings',
        href: AiSettingsController.index.url(),
        icon: Brain,
    },
    {
        title: 'Decision Agent',
        href: DecisionAgentSettingsController.index.url(),
        icon: Bot,
    },
    {
        title: 'AI Usage',
        href: AiUsageController.index.url(),
        icon: BarChart3,
    },
    {
        title: 'AI Conversations',
        href: AiConversationController.index.url(),
        icon: MessageSquare,
    },
    {
        title: 'AI Prices',
        href: AiModelPriceController.index.url(),
        icon: DollarSign,
    },
];

const adminNavItems = computed<NavItem[]>(() => [
    {
        title: 'Connections',
        href: ServiceConnectionController.index.url(),
        icon: LinkIcon,
    },
    {
        title: 'Users',
        href: UserController.index.url(),
        icon: Users,
    },
    {
        title: 'Approval Rules',
        href: ActionTypeConfigController.index.url(),
        icon: Shield,
    },
    {
        title: 'Statistics',
        href: AdminStatisticsController().url,
        icon: ChartLine,
    },
    ...(aiEnabled.value ? aiAdminNavItems : []),
    {
        title: 'Webhook Log',
        href: WebhookLogController.index.url(),
        icon: WebhookIcon,
    },
    {
        title: 'Jobs',
        href: JobsController.index.url(),
        icon: ListTodo,
    },
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="overviewNavItems" label="Overview" />
            <NavMain :items="mediaNavItems" label="Media" />
            <NavMain :items="liveNavItems" label="Live" />
            <NavMain
                v-if="aiEnabled && isAdmin"
                :items="aiNavItems"
                label="Assistant"
            />
            <NavMain v-if="isAdmin" :items="adminNavItems" label="Admin" />
        </SidebarContent>

        <SidebarFooter>
            <SidebarMenu v-if="aiEnabled && isAdmin">
                <SidebarMenuItem>
                    <SidebarMenuButton
                        as="button"
                        type="button"
                        title="Open AI assistant (⌘J)"
                        @click="openChat()"
                    >
                        <Sparkles />
                        <span>AI Assistant</span>
                        <kbd
                            class="font-mono-tabular ml-auto rounded border border-border bg-card px-1 text-[10px] text-muted-foreground"
                        >
                            ⌘J
                        </kbd>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <NavUser />
            <AppVersion />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
