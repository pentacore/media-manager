import { toast } from 'vue-sonner';
import { jsonRequest } from '@/composables/useAiChat';
import type { BulkSummary } from '@/types';

/**
 * Posts one bulk request and shows the server-worded summary toast (a fetch
 * path, so vue-sonner is called directly). Resolves to the summary, or to
 * null when the request itself was refused (validation, pin, permission) —
 * that message is toasted too.
 */
export async function submitBulk(
    url: string,
    body: Record<string, unknown>,
): Promise<BulkSummary | null> {
    try {
        const summary = await jsonRequest<BulkSummary>('post', url, body);
        const notify =
            summary.toast.type === 'error'
                ? toast.error
                : summary.toast.type === 'info'
                  ? toast.info
                  : toast.success;

        notify(summary.toast.message);

        return summary;
    } catch (error) {
        toast.error(
            error instanceof Error ? error.message : 'The bulk action failed.',
        );

        return null;
    }
}
