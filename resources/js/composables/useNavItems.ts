import { usePage } from '@inertiajs/vue3';
import {
    Activity,
    Antenna,
    BellRing,
    Bot,
    Brain,
    CalendarDays,
    Captions,
    ChartLine,
    Clapperboard,
    Clock,
    Compass,
    DollarSign,
    Download,
    Film,
    Heart,
    HeartPulse,
    Inbox,
    LayoutGrid,
    Link as LinkIcon,
    ListChecks,
    ListTodo,
    MessageSquare,
    Play,
    Radar,
    Replace,
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
import MediaReplacementSettingsController from '@/actions/App/Http/Controllers/Admin/MediaReplacementSettingsController';
import NotificationDestinationController from '@/actions/App/Http/Controllers/Admin/NotificationDestinationController';
import ServiceConnectionController from '@/actions/App/Http/Controllers/Admin/ServiceConnectionController';
import AdminStatisticsController from '@/actions/App/Http/Controllers/Admin/StatisticsController';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import WebhookLogController from '@/actions/App/Http/Controllers/Admin/WebhookLogController';
import BazarrOverviewController from '@/actions/App/Http/Controllers/Bazarr/OverviewController';
import NowPlayingController from '@/actions/App/Http/Controllers/Emby/NowPlayingController';
import WatchHistoryController from '@/actions/App/Http/Controllers/Emby/WatchHistoryController';
import LibraryActivityController from '@/actions/App/Http/Controllers/Library/ActivityController';
import CalendarController from '@/actions/App/Http/Controllers/Library/CalendarController';
import WantedController from '@/actions/App/Http/Controllers/Library/WantedController';
import AnimeController from '@/actions/App/Http/Controllers/Media/AnimeController';
import DiscoverController from '@/actions/App/Http/Controllers/Media/DiscoverController';
import MovieController from '@/actions/App/Http/Controllers/Media/MovieController';
import MyRequestController from '@/actions/App/Http/Controllers/Media/MyRequestController';
import RequestController from '@/actions/App/Http/Controllers/Media/RequestController';
import SearchController from '@/actions/App/Http/Controllers/Media/SearchController';
import SeriesController from '@/actions/App/Http/Controllers/Media/SeriesController';
import ServiceHealthController from '@/actions/App/Http/Controllers/Monitoring/ServiceHealthController';
import SearchIndexersController from '@/actions/App/Http/Controllers/Prowlarr/SearchIndexersController';
import SabnzbdQueueController from '@/actions/App/Http/Controllers/Sabnzbd/QueueController';
import StatisticsController from '@/actions/App/Http/Controllers/StatisticsController';
import WhisparrController from '@/actions/App/Http/Controllers/Whisparr/WhisparrController';
import { useCan } from '@/composables/useCan';
import type { NavCounts } from '@/composables/useNavCounts';
import { dashboard } from '@/routes';
import type { NavGroup, NavItem } from '@/types';

/**
 * Sidebar navigation structure. Counts are injected rather than imported so
 * consumers that render no badges (the command palette) open no websocket
 * channels.
 */
export function useNavItems(counts?: NavCounts): ComputedRef<NavGroup[]> {
    const page = usePage();
    const { can } = useCan();

    const aiEnabled = computed(() => Boolean(page.props.ai?.enabled));

    function visible(item: NavItem): boolean {
        if (item.requiresSeerr === true && !page.props.integrations?.seerr) {
            return false;
        }

        if (
            item.requiresProwlarr === true &&
            !page.props.integrations?.prowlarr
        ) {
            return false;
        }

        if (
            item.requiresWhisparr === true &&
            !page.props.integrations?.whisparr
        ) {
            return false;
        }

        return item.ability === undefined || can(item.ability);
    }

    function prune(groups: NavGroup[]): NavGroup[] {
        return groups
            .map((group) => ({
                ...group,
                items: group.items
                    .filter(visible)
                    .map((item) =>
                        item.children
                            ? {
                                  ...item,
                                  children: item.children.filter(visible),
                              }
                            : item,
                    )
                    .filter(
                        (item) =>
                            item.children === undefined ||
                            item.children.length > 0,
                    ),
            }))
            .filter((group) => group.items.length > 0);
    }

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
                        ability: 'manage-library',
                    },
                    {
                        title: 'Watch stats',
                        href: StatisticsController().url,
                        icon: ChartLine,
                        ability: 'manage-library',
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
                        ability: 'view-library',
                    },
                    {
                        title: 'Movies',
                        href: MovieController.index.url(),
                        icon: Film,
                        ability: 'view-library',
                    },
                    {
                        title: 'Whisparr',
                        href: WhisparrController.index.url(),
                        icon: Clapperboard,
                        ability: 'admin',
                        requiresWhisparr: true,
                    },
                    {
                        title: 'Discover',
                        href: DiscoverController.index.url(),
                        icon: Compass,
                        ability: 'request-media',
                        requiresSeerr: true,
                    },
                    {
                        title: 'Calendar',
                        href: CalendarController.url(),
                        icon: CalendarDays,
                        ability: 'view-library',
                    },
                    {
                        title: 'Wanted',
                        href: WantedController.url(),
                        icon: Radar,
                        ability: 'manage-library',
                        badge: counts
                            ? () => counts.wantedMissing.value
                            : undefined,
                    },
                    {
                        title: 'Requests',
                        href: RequestController.index.url(),
                        icon: Heart,
                        ability: 'manage-requests',
                    },
                    {
                        title: 'My requests',
                        href: MyRequestController.index.url(),
                        icon: ListChecks,
                        ability: 'request-media',
                        requiresSeerr: true,
                    },
                    {
                        title: 'Seasonal Anime',
                        href: AnimeController.index.url(),
                        icon: Sprout,
                        ability: 'manage-requests',
                    },
                    {
                        title: 'Subtitles',
                        href: BazarrOverviewController.url(),
                        icon: Captions,
                        ability: 'manage-library',
                    },
                    {
                        title: 'Indexer search',
                        href: SearchIndexersController().url,
                        icon: Antenna,
                        ability: 'manage-library',
                        requiresProwlarr: true,
                    },
                    {
                        title: 'Search',
                        href: SearchController.index.url(),
                        icon: Search,
                        mobileOnly: true,
                        ability: 'view-library',
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
                        ability: 'manage-library',
                    },
                    {
                        title: 'Grab queue',
                        href: LibraryActivityController.queue.url(),
                        icon: Activity,
                        badge: counts
                            ? () => counts.libraryIntervention.value
                            : undefined,
                        ability: 'manage-library',
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
                        ability: 'manage-library',
                    },
                    {
                        title: 'Activity log',
                        href: ActivityLogController.index.url(),
                        icon: ScrollText,
                        ability: 'manage-library',
                    },
                ],
            },
        ];

        const adminItems: NavGroup['items'] = [
            {
                title: 'Configuration',
                icon: Settings2,
                ability: 'admin',
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
                    {
                        title: 'Media Replacement',
                        href: MediaReplacementSettingsController.index.url(),
                        icon: Replace,
                        badge: counts
                            ? () => counts.replacementAttention.value
                            : undefined,
                    },
                    {
                        title: 'Notification destinations',
                        href: NotificationDestinationController.index.url(),
                        icon: BellRing,
                    },
                ],
            },
        ];

        if (aiEnabled.value) {
            adminItems.push({
                title: 'AI',
                icon: Brain,
                ability: 'admin',
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
            ability: 'admin',
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

        return prune(groups);
    });
}
