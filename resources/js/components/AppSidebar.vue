<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { computed, nextTick, onMounted, onUnmounted } from 'vue';
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
import { useCan } from '@/composables/useCan';
import { useNavCounts } from '@/composables/useNavCounts';
import { useNavItems } from '@/composables/useNavItems';
import { dashboard } from '@/routes';
import type { NavGroup, NavItem } from '@/types';

const page = usePage();
const { isAdmin } = useCan();
const { isMobile, setOpenMobile } = useSidebar();
const { openChat } = useAiChat();

let stopNavigateListener: (() => void) | null = null;

// The phone sidebar is a Sheet over the page; close it once a visit lands.
// The layout persists across pages, so this registers once per layout mount.
onMounted(() => {
    stopNavigateListener = router.on('navigate', () => setOpenMobile(false));
});

onUnmounted(() => {
    stopNavigateListener?.();
    stopNavigateListener = null;
});

/**
 * A visit to the page already open replaces it and fires no `navigate`, so
 * the phone sheet also closes on the nav link click itself. Desktop keeps
 * its collapse state: only the mobile sheet's open flag changes.
 */
function closeMobileOnLinkClick(event: MouseEvent): void {
    if (
        isMobile.value &&
        (event.target as Element | null)?.closest('a[href]')
    ) {
        setOpenMobile(false);
    }
}

/**
 * The phone sidebar is itself a sheet. Close it, then open the chat sheet on
 * the next tick, once the sidebar has stopped trapping focus. The sidebar's
 * own button is still animating out at that point, so the sidebar trigger
 * takes focus first: the chat sheet records the focused element as the one
 * to return to on close, and the trigger outlives the sidebar. On the chat
 * page the chat is already on screen, so only the sidebar closes.
 */
function openChatFromMobile(): void {
    setOpenMobile(false);

    if (page.component === 'AI/Chat') {
        return;
    }

    void nextTick(() => {
        document
            .querySelector<HTMLElement>('[data-sidebar="trigger"]')
            ?.focus();
        openChat();
    });
}

const navGroups = useNavItems(useNavCounts());

function keepItem(item: NavItem): boolean {
    return !item.mobileOnly || isMobile.value;
}

const visibleGroups = computed<NavGroup[]>(() =>
    navGroups.value.map((group) => ({
        label: group.label,
        items: group.items
            .filter(keepItem)
            .map((item) =>
                item.children
                    ? { ...item, children: item.children.filter(keepItem) }
                    : item,
            ),
    })),
);

const aiEnabled = computed(() => Boolean(page.props.ai?.enabled));

const showAiAffordance = computed(() => aiEnabled.value && isAdmin.value);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader @click="closeMobileOnLinkClick">
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

        <SidebarContent @click="closeMobileOnLinkClick">
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
                        data-sidebar-ai-chat-open
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
                    <template v-else>
                        <!-- The sidebar is a sheet up to 768 px, but the
                             header's AI entry opens the chat sheet only below
                             sm: from sm both navigate to the chat page. -->
                        <SidebarMenuButton
                            as="button"
                            type="button"
                            class="sm:hidden"
                            data-sidebar-ai-chat
                            @click="openChatFromMobile"
                        >
                            <Sparkles />
                            <span>AI Assistant</span>
                        </SidebarMenuButton>
                        <SidebarMenuButton as-child class="max-sm:hidden">
                            <Link
                                :href="AIChatController.index.url()"
                                data-sidebar-ai-chat-link
                            >
                                <Sparkles />
                                <span>AI Assistant</span>
                            </Link>
                        </SidebarMenuButton>
                    </template>
                </SidebarMenuItem>
            </SidebarMenu>
            <NavUser />
            <AppVersion />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
