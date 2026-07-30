<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import AIChatController from '@/actions/App/Http/Controllers/AI/ChatController';
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
    useSidebar,
} from '@/components/ui/sidebar';
import { useAiChat } from '@/composables/useAiChat';
import { useNavCounts } from '@/composables/useNavCounts';
import { useNavItems } from '@/composables/useNavItems';
import { dashboard } from '@/routes';
import type { NavGroup } from '@/types';

const page = usePage();
const { isMobile } = useSidebar();
const { openChat } = useAiChat();

const navGroups = useNavItems(useNavCounts());

const visibleGroups = computed<NavGroup[]>(() =>
    navGroups.value.map((group) => ({
        label: group.label,
        items: group.items.filter((item) => !item.mobileOnly || isMobile.value),
    })),
);

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

const showAiAffordance = computed(() => aiEnabled.value && isAdmin.value);
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
            <NavMain
                v-for="group in visibleGroups"
                :key="group.label"
                :items="group.items"
                :label="group.label"
            />
        </SidebarContent>

        <SidebarFooter>
            <SidebarMenu v-if="showAiAffordance">
                <SidebarMenuItem>
                    <SidebarMenuButton
                        v-if="!isMobile"
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
                    <SidebarMenuButton v-else as-child>
                        <Link :href="AIChatController.index.url()">
                            <Sparkles />
                            <span>AI Assistant</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <NavUser />
            <AppVersion />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
