import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    badge?: () => number;
    /**
     * Items that exist only because their hotkey is unreachable on touch.
     * The sidebar renders them when `isMobile`; the command palette always
     * drops them.
     */
    mobileOnly?: boolean;
};

export type NavGroup = {
    label: string;
    items: NavItem[];
};
