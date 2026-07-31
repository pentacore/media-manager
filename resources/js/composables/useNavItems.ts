import { usePage } from '@inertiajs/vue3';
import {
    Activity,
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
    ListTodo,
    MessageSquare,
    Play,
    ScrollText,
    Search,
    Settings2,
    Shield,
    Sprout,
    Stethoscope,
    Tv,
    Users,
    Webhook as WebhookIcon,
} from '@lucide/vue';
import type { ComputedRef } from 'vue';
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
import type { NavCounts } from '@/composables/useNavCounts';
import { dashboard } from '@/routes';
import type { NavGroup } from '@/types';

/**
 * Sidebar navigation structure. Counts are injected rather than imported so
 * consumers that render no badges (the command palette) open no websocket
 * channels.
 */
export function useNavItems(counts?: NavCounts): ComputedRef<NavGroup[]> {
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
            (page.props as unknown as { ai?: { enabled?: boolean } }).ai
                ?.enabled,
        ),
    );

    return computed<NavGroup[]>(() => {
        const groups: NavGroup[] = [
            {
                label: 'Overview',
                items: [
                    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
                    {
                        title: 'Action Queue',
                        href: ActionRequestController.index.url(),
                        icon: Inbox,
                        badge: counts
                            ? () => counts.pendingActions.value
                            : undefined,
                    },
                    {
                        title: 'Watch stats',
                        href: StatisticsController().url,
                        icon: ChartLine,
                    },
                ],
            },
            {
                label: 'Media',
                items: [
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
                        mobileOnly: true,
                    },
                ],
            },
            {
                label: 'Activity',
                items: [
                    {
                        title: 'Now Playing',
                        href: NowPlayingController().url,
                        icon: Play,
                        badge: counts
                            ? () => counts.activeSessions.value
                            : undefined,
                    },
                    {
                        title: 'Downloads',
                        href: SabnzbdQueueController.index.url(),
                        icon: Download,
                        // Show "queued + still-in-history" so the badge
                        // surfaces both active downloads and stuck
                        // post-processing rows. SAB prunes imported items
                        // itself, so anything here means "needs a look".
                        badge: counts
                            ? () =>
                                  counts.sabnzbdQueued.value +
                                  counts.sabnzbdCompleted.value
                            : undefined,
                    },
                    {
                        title: 'Grab queue',
                        href: LibraryActivityController.queue.url(),
                        icon: Activity,
                        badge: counts
                            ? () => counts.libraryIntervention.value
                            : undefined,
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
                ],
            },
        ];

        if (!isAdmin.value) {
            return groups;
        }

        const adminItems: NavGroup['items'] = [
            {
                title: 'Configuration',
                icon: Settings2,
                children: [
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
                ],
            },
        ];

        if (aiEnabled.value) {
            adminItems.push({
                title: 'AI',
                icon: Brain,
                children: [
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
                        icon: ChartLine,
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
                ],
            });
        }

        adminItems.push({
            title: 'Diagnostics',
            icon: Stethoscope,
            children: [
                {
                    title: 'System stats',
                    href: AdminStatisticsController().url,
                    icon: ChartLine,
                },
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
            ],
        });

        groups.push({ label: 'Admin', items: adminItems });

        return groups;
    });
}
