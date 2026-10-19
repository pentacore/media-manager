import type { ActionRequestResource } from '@/typefinder/resources/ActionRequestResource';

/** One Action Queue row, as the page's `requests` prop lists it. */
export type ActionRequestRow = ActionRequestResource;

export interface PaginatorLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface PaginatorMeta {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

/** The Action Queue's paginated `requests` prop. */
export interface ActionQueueRequests {
    data: ActionRequestRow[];
    links: PaginatorLink[];
    meta: PaginatorMeta;
}
