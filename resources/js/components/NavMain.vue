<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { ref } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

withDefaults(
    defineProps<{
        items: NavItem[];
        label?: string;
    }>(),
    {
        label: 'Platform',
    },
);

const { isCurrentOrParentUrl } = useCurrentUrl();
const { state, setOpen } = useSidebar();

const STORAGE_PREFIX = 'sidebar:nav:';

/** Locally-toggled open state, keyed by item title. */
const toggled = ref<Record<string, boolean>>({});

function storageKey(title: string): string {
    return `${STORAGE_PREFIX}${title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
}

function readStored(title: string): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.localStorage.getItem(storageKey(title)) === 'open';
}

/**
 * A leaf always carries an `href` (the type allows `href` or `children`, never
 * neither), but TypeScript cannot see that invariant. Resolving it here keeps
 * non-null assertions out of template expressions and turns a future taxonomy
 * mistake into a loud failure instead of a silently broken link.
 */
function resolveHref(item: NavItem): NonNullable<NavItem['href']> {
    if (item.href === undefined) {
        throw new Error(
            `Nav item [${item.title}] has neither href nor parent.`,
        );
    }

    return item.href;
}

/**
 * Prefix-matched, not exact: several leaves own descendant pages
 * (`/admin/connections/create`, `/admin/webhook-log/{event}`,
 * `/media/series/{id}`, …), and on those an exact match makes the nav claim
 * nothing is selected — which also shuts the parent group, contradicting
 * isOpen() below. Matches the settings sidebar, which highlights with
 * isCurrentOrParentUrl() for the same reason.
 *
 * Two rows can only light up at once if one leaf href is a prefix of another;
 * no two are, across every group (`/admin/ai-settings`, `/admin/ai-usage`,
 * `/admin/ai-prices` and `/admin/ai/conversations` all diverge after
 * `/admin/ai`; `/actions/requests` vs `/actions/rules` after `/actions/r`;
 * `/statistics` vs `/admin/statistics` share no prefix at all).
 */
function isActiveItem(item: NavItem): boolean {
    return item.href !== undefined && isCurrentOrParentUrl(item.href);
}

function hasActiveChild(item: NavItem): boolean {
    return (item.children ?? []).some(isActiveItem);
}

/**
 * A group holding the current page is always open, so deep-linking never
 * lands on a page whose nav parent is shut.
 */
function isOpen(item: NavItem): boolean {
    if (hasActiveChild(item)) {
        return true;
    }

    return toggled.value[item.title] ?? readStored(item.title);
}

function persist(item: NavItem, open: boolean): void {
    toggled.value[item.title] = open;

    if (typeof window !== 'undefined') {
        window.localStorage.setItem(
            storageKey(item.title),
            open ? 'open' : 'closed',
        );
    }
}

/**
 * Sub-buttons are display:none in rail mode, so a parent click there has to
 * widen the sidebar first or the children would be unreachable.
 */
function onToggle(item: NavItem, next: boolean): void {
    if (state.value === 'collapsed') {
        setOpen(true);
        persist(item, true);

        return;
    }

    persist(item, next);
}

/** A closed parent must not hide a pending count. */
function rollupBadge(item: NavItem): number {
    if (item.badge) {
        return item.badge();
    }

    return (item.children ?? []).reduce(
        (sum, child) => sum + (child.badge?.() ?? 0),
        0,
    );
}
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel
            class="text-[10.5px] font-semibold tracking-[0.08em] text-fg-subtle uppercase"
        >
            {{ label }}
        </SidebarGroupLabel>
        <SidebarMenu>
            <template v-for="item in items" :key="item.title">
                <Collapsible
                    v-if="item.children"
                    as-child
                    :open="isOpen(item)"
                    @update:open="(next: boolean) => onToggle(item, next)"
                >
                    <SidebarMenuItem>
                        <CollapsibleTrigger as-child>
                            <SidebarMenuButton
                                :tooltip="item.title"
                                class="text-[13px] font-medium"
                            >
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                                <ChevronRight
                                    class="ml-auto transition-transform duration-200"
                                    :class="{ 'rotate-90': isOpen(item) }"
                                />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>
                        <SidebarMenuBadge
                            v-if="!isOpen(item) && rollupBadge(item) > 0"
                            class="font-mono-tabular border border-border bg-card text-[10.5px] text-muted-foreground"
                        >
                            {{ rollupBadge(item) }}
                        </SidebarMenuBadge>
                        <CollapsibleContent>
                            <SidebarMenuSub>
                                <SidebarMenuSubItem
                                    v-for="child in item.children"
                                    :key="child.title"
                                >
                                    <SidebarMenuSubButton
                                        as-child
                                        :is-active="isActiveItem(child)"
                                    >
                                        <Link :href="resolveHref(child)">
                                            <component :is="child.icon" />
                                            <span>{{ child.title }}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </SidebarMenuItem>
                </Collapsible>
                <SidebarMenuItem v-else>
                    <SidebarMenuButton
                        as-child
                        :is-active="isActiveItem(item)"
                        :tooltip="item.title"
                        class="text-[13px] font-medium data-[active=true]:bg-accent/15 data-[active=true]:text-accent"
                    >
                        <Link :href="resolveHref(item)">
                            <component :is="item.icon" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                    <SidebarMenuBadge
                        v-if="item.badge && item.badge() > 0"
                        class="font-mono-tabular text-[10.5px]"
                        :class="[
                            isActiveItem(item)
                                ? 'border-transparent bg-accent/20 text-accent'
                                : 'border border-border bg-card text-muted-foreground',
                        ]"
                    >
                        {{ item.badge() }}
                    </SidebarMenuBadge>
                </SidebarMenuItem>
            </template>
        </SidebarMenu>
    </SidebarGroup>
</template>
