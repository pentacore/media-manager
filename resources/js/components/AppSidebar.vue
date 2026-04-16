<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import { Activity as ActivityIcon, Film, HeartPulse, History, Inbox, LayoutGrid, Link2, Link as LinkIcon, Search, Shield, Sparkles, Tv, Users, Zap } from 'lucide-vue-next'
import AppLogo from '@/components/AppLogo.vue'
import NavFooter from '@/components/NavFooter.vue'
import NavMain from '@/components/NavMain.vue'
import NavUser from '@/components/NavUser.vue'
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar'
import { dashboard } from '@/routes'
import ServiceConnectionController from '@/actions/App/Http/Controllers/Admin/ServiceConnectionController'
import UserController from '@/actions/App/Http/Controllers/Admin/UserController'
import SeriesController from '@/actions/App/Http/Controllers/Media/SeriesController'
import MovieController from '@/actions/App/Http/Controllers/Media/MovieController'
import RequestController from '@/actions/App/Http/Controllers/Media/RequestController'
import SearchController from '@/actions/App/Http/Controllers/Media/SearchController'
import NowPlayingController from '@/actions/App/Http/Controllers/Emby/NowPlayingController'
import WatchHistoryController from '@/actions/App/Http/Controllers/Emby/WatchHistoryController'
import ServiceHealthController from '@/actions/App/Http/Controllers/Monitoring/ServiceHealthController'
import UserLinkController from '@/actions/App/Http/Controllers/Emby/UserLinkController'
import ActionRequestController from '@/actions/App/Http/Controllers/Actions/ActionRequestController'
import ActionTypeConfigController from '@/actions/App/Http/Controllers/Actions/ActionTypeConfigController'
import AIChatController from '@/actions/App/Http/Controllers/AI/ChatController'
import type { NavItem } from '@/types'
import { computed } from 'vue'

const page = usePage()

const isAdmin = computed(() => {
    const role = page.props.auth.user?.role
    if (!role) return false
    const value = typeof role === 'string' ? role : role.value
    return value === 'admin'
})

const aiEnabled = computed(() => Boolean((page.props as unknown as { ai?: { enabled?: boolean } }).ai?.enabled))

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Search',
        href: SearchController.index.url(),
        icon: Search,
    },
]

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
]

const monitoringNavItems: NavItem[] = [
    {
        title: 'Now Playing',
        href: NowPlayingController().url,
        icon: ActivityIcon,
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
]

const automationNavItems: NavItem[] = [
    {
        title: 'Action Requests',
        href: ActionRequestController.index.url(),
        icon: Zap,
    },
]

const aiNavItems: NavItem[] = [
    {
        title: 'AI Assistant',
        href: AIChatController.index.url(),
        icon: Sparkles,
    },
]

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
]
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
            <NavMain v-if="aiEnabled && isAdmin" :items="aiNavItems" label="AI" />
            <NavMain v-if="isAdmin" :items="adminNavItems" label="Admin" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
