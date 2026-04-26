<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity as ActivityIcon,
    Film,
    HeartPulse,
    History,
    Inbox,
    LayoutGrid,
    Link2,
    Link as LinkIcon,
    ScrollText,
    Search,
    Shield,
    Sparkles,
    Tv,
    Users,
    Zap,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watchEffect } from 'vue';
import ActionRequestController from '@/actions/App/Http/Controllers/Actions/ActionRequestController';
import ActionTypeConfigController from '@/actions/App/Http/Controllers/Actions/ActionTypeConfigController';
import ActivityLogController from '@/actions/App/Http/Controllers/ActivityLogController';
import ServiceConnectionController from '@/actions/App/Http/Controllers/Admin/ServiceConnectionController';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import AIChatController from '@/actions/App/Http/Controllers/AI/ChatController';
import NowPlayingController from '@/actions/App/Http/Controllers/Emby/NowPlayingController';
import UserLinkController from '@/actions/App/Http/Controllers/Emby/UserLinkController';
import WatchHistoryController from '@/actions/App/Http/Controllers/Emby/WatchHistoryController';
import MovieController from '@/actions/App/Http/Controllers/Media/MovieController';
import RequestController from '@/actions/App/Http/Controllers/Media/RequestController';
import SearchController from '@/actions/App/Http/Controllers/Media/SearchController';
import SeriesController from '@/actions/App/Http/Controllers/Media/SeriesController';
import ServiceHealthController from '@/actions/App/Http/Controllers/Monitoring/ServiceHealthController';
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
const userId = computed(() => page.props.auth.user?.id);

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

    activeSessions.value =
        sessionTimestamps.size > 0
            ? sessionTimestamps.size
            : Math.max(0, activeSessions.value);
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

    if (typeof userId.value === 'number') {
        const userChannel = `App.Models.User.${userId.value}`;
        privateChannel(userChannel)
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
            );
    }

    activitySessionTimer = setInterval(pruneStaleSessions, 60_000);
});

onUnmounted(() => {
    leaveChannel('emby.activity');

    if (typeof userId.value === 'number') {
        leaveChannel(`App.Models.User.${userId.value}`);
    }

    if (activitySessionTimer) {
        clearInterval(activitySessionTimer);
        activitySessionTimer = null;
    }
});

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Activity Log',
        href: ActivityLogController().url,
        icon: ScrollText,
    },
    {
        title: 'Search',
        href: SearchController.index.url(),
        icon: Search,
    },
];

const mediaNavItems: NavItem[] = [
    {
        title: 'Series',
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
        icon: Inbox,
    },
];

const monitoringNavItems = computed<NavItem[]>(() => [
    {
        title: 'Now Playing',
        href: NowPlayingController().url,
        icon: ActivityIcon,
        badge: () => activeSessions.value,
    },
    {
        title: 'Watch History',
        href: WatchHistoryController().url,
        icon: History,
    },
    {
        title: 'Service Health',
        href: ServiceHealthController().url,
        icon: HeartPulse,
    },
]);

const automationNavItems = computed<NavItem[]>(() => [
    {
        title: 'Action Requests',
        href: ActionRequestController.index.url(),
        icon: Zap,
        badge: () => pendingActions.value,
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
        title: 'Emby Links',
        href: UserLinkController.index.url(),
        icon: Link2,
    },
    {
        title: 'Action Rules',
        href: ActionTypeConfigController.index.url(),
        icon: Shield,
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
            <NavMain :items="mainNavItems" />
            <NavMain :items="mediaNavItems" label="Media" />
            <NavMain :items="monitoringNavItems" label="Monitoring" />
            <NavMain :items="automationNavItems" label="Automation" />
            <NavMain
                v-if="aiEnabled && isAdmin"
                :items="aiNavItems"
                label="AI"
            />
            <NavMain v-if="isAdmin" :items="adminNavItems" label="Admin" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
