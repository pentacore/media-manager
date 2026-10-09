import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import type { Ability } from './auth';

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
    /** Hidden unless `auth.can[ability]` is true. Children inherit nothing — tag each. */
    ability?: Ability;
    /** Hidden unless an active Seerr connection exists (shared `integrations.seerr`). */
    requiresSeerr?: boolean;
    /** Hidden unless an active Prowlarr connection exists (shared `integrations.prowlarr`). */
    requiresProwlarr?: boolean;
    /** Hidden unless an active Whisparr connection exists and the viewer is an admin (shared `integrations.whisparr`). */
    requiresWhisparr?: boolean;
    /**
     * Hover prefetch, on unless `false`. Set `false` when the page, in its
     * initial (non-deferred) props, reads an upstream service that is
     * uncached or whose failures are not cached, runs heavy un-deferred DB
     * aggregation, or dispatches work/has a side effect on GET, so a passing
     * hover never pays that cost.
     */
    prefetch?: boolean;
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
