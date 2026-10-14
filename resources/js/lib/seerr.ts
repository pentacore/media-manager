import type { TitleStatus } from '@/types';

/** The pill for a title Seerr has blocklisted: never requestable. */
export const BLOCKLISTED_PILL = {
    status: 'declined',
    label: 'Blocklisted',
} as const;

/** StatusPill props for a Seerr title state; `none` renders no pill. */
export function titleStatusPill(
    status: TitleStatus,
): { status: string; label: string } | null {
    switch (status) {
        case 'available':
            return { status: 'available', label: 'Available' };
        case 'partially_available':
            return { status: 'approved', label: 'Partially available' };
        case 'requested':
            return { status: 'approved', label: 'Requested' };
        case 'pending':
            return { status: 'pending', label: 'Pending' };
        case 'blocklisted':
            return BLOCKLISTED_PILL;
        default:
            return null;
    }
}
