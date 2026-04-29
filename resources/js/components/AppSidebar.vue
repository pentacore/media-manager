<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    BarChart3,
    Brain,
    Clock,
    DollarSign,
    Download,
    Film,
    Heart,
    HeartPulse,
    Inbox,
    LayoutGrid,
    Link as LinkIcon,
    Play,
    ScrollText,
    Search,
    Shield,
    Sparkles,
    Tv,
    Users,
    Webhook as WebhookIcon,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watchEffect } from 'vue';
import ActionRequestController from '@/actions/App/Http/Controllers/Actions/ActionRequestController';
import ActionTypeConfigController from '@/actions/App/Http/Controllers/Actions/ActionTypeConfigController';
import ActivityLogController from '@/actions/App/Http/Controllers/ActivityLogController';
import AiModelPriceController from '@/actions/App/Http/Controllers/Admin/AiModelPriceController';
import AiSettingsController from '@/actions/App/Http/Controllers/Admin/AiSettingsController';
import AiUsageController from '@/actions/App/Http/Controllers/Admin/AiUsageController';
import ServiceConnectionController from '@/actions/App/Http/Controllers/Admin/ServiceConnectionController';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import WebhookLogController from '@/actions/App/Http/Controllers/Admin/WebhookLogController';
import AIChatController from '@/actions/App/Http/Controllers/AI/ChatController';
import NowPlayingController from '@/actions/App/Http/Controllers/Emby/NowPlayingController';
import WatchHistoryController from '@/actions/App/Http/Controllers/Emby/WatchHistoryController';
import LibraryActivityController from '@/actions/App/Http/Controllers/Library/ActivityController';
import MovieController from '@/actions/App/Http/Controllers/Media/MovieController';
import RequestController from '@/actions/App/Http/Controllers/Media/RequestController';
import SearchController from '@/actions/App/Http/Controllers/Media/SearchController';
import SeriesController from '@/actions/App/Http/Controllers/Media/SeriesController';
import ServiceHealthController from '@/actions/App/Http/Controllers/Monitoring/ServiceHealthController';
import SabnzbdQueueController from '@/actions/App/Http/Controllers/Sabnzbd/QueueController';
import AppLogo from '@/components/AppLogo.vue';
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

const initialNav = (
    page.props as unknown as {
        nav?: { pendingActions?: number; activeSessions?: number };
    }
).nav;

const pendingActions = ref(initialNav?.pendingActions ?? 0);
const activeSessions = ref(initialNav?.activeSessions ?? 0);
const recentSessionIds = new Set<number>();

watchEffect(() => {
    const nav = (
        page.props as unknown as {
            nav?: { pendingActions?: number; activeSessions?: number };
        }
    ).nav;

    if (nav) {
        pendingActions.value = nav.pendingActions ?? 0;
        activeSessions.value = nav.activeSessions ?? 0;
    }
});

const { privateChannel, leaveChannel } = useWebSocket();

let activitySessionTimer: ReturnType<typeof setInterval> | null = null;
const sessionExpiryMs = 10 * 60 * 1000;
const sessionTimestamps = new Map<number, number>();

function pruneStaleSessions(): void {
    const cutoff = Date.now() - sessionExpiryMs;

    for (const [id, ts] of sessionTimestamps) {
        if (ts < cutoff) {
            sessionTimestamps.delete(id);
            recentSessionIds.delete(id);
        }
    }

    activeSessions.value = sessionTimestamps.size;
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

onMounted(() => {
    privateChannel('emby.activity').listen(
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
    );

    privateChannel('members.actions')
        .listen(
            '.ActionRequestCreated',
            (event: ActionRequestCreatedPayload) => {
                if (event.status === 'pending' && !pendingIds.has(event.id)) {
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
        );

    activitySessionTimer = setInterval(pruneStaleSessions, 60_000);
});

onUnmounted(() => {
    leaveChannel('emby.activity');
    leaveChannel('members.actions');

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
]);

const mediaNavItems: NavItem[] = [
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
        title: 'Search',
        href: SearchController.index.url(),
        icon: Search,
    },
    {
        title: 'Downloads',
        href: SabnzbdQueueController.index.url(),
        icon: Download,
    },
    {
        title: 'Library activity',
        href: LibraryActivityController.queue.url(),
        icon: Activity,
    },
];

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

const adminNavItems: NavItem[] = [
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
        title: 'AI Settings',
        href: AiSettingsController.index.url(),
        icon: Brain,
    },
    {
        title: 'AI Usage',
        href: AiUsageController.index.url(),
        icon: BarChart3,
    },
    {
        title: 'AI Prices',
        href: AiModelPriceController.index.url(),
        icon: DollarSign,
    },
    {
        title: 'Webhook Log',
        href: WebhookLogController.index.url(),
        icon: WebhookIcon,
    },
];
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
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
