import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    /** Absent on parent rows, which exist only to hold `children`. */
    href?: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    badge?: () => number;
    /**
     * One level only — a child must never define `children` of its own.
     * A NavItem carries `href` or `children`, never both and never neither.
     */
    children?: NavItem[];
    /**
     * Items that exist only because their hotkey is unreachable on touch.
     * The sidebar renders them when `isMobile`; the command palette always
     * drops them.
     */
    mobileOnly?: boolean;
};

/**
 * A `NavItem` that is guaranteed to be a leaf. Flat menus (the header, the
 * settings sub-nav, the footer links) never nest, so they keep `href`
 * required rather than null-checking it at every use site.
 */
export type NavLink = NavItem & {
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavGroup = {
    label: string;
    items: NavItem[];
};
