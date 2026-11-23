import type { PillVariant } from '@/components/mm';

/**
 * The pill variant for an agent decision's status, shared by the webhook
 * log's list and detail pages.
 */
export function agentDecisionVariant(status: string): PillVariant {
    switch (status) {
        case 'completed':
            return 'ok';
        case 'failed':
            return 'danger';
        case 'resolved_by_classifier':
            return 'info';
        default:
            return 'default';
    }
}
