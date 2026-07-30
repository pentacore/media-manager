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
import { computed } from 'vue';
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
import { useNavCounts } from '@/composables/useNavCounts';
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

const { openChat } = useAiChat();

const {
    pendingActions,
    activeSessions,
    libraryIntervention,
    sabnzbdQueued,
    sabnzbdCompleted,
} = useNavCounts();

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
