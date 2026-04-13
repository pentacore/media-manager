<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import { Film, Inbox, LayoutGrid, Link as LinkIcon, Search, Tv, Users } from 'lucide-vue-next'
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
import type { NavItem } from '@/types'
import { computed } from 'vue'

const page = usePage()

const isAdmin = computed(() => {
    const role = page.props.auth.user?.role
    if (!role) return false
    const value = typeof role === 'string' ? role : role.value
    return value === 'admin'
})

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
            <NavMain v-if="isAdmin" :items="adminNavItems" label="Admin" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
