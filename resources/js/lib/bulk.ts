import { nextTick } from 'vue';
import { toast } from 'vue-sonner';
import { jsonRequest } from '@/lib/http';
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

/**
 * After a successful run the selection clears and the bulk bar (holding the
 * focused button, or a dialog's return-focus target) unmounts, dropping
 * focus to <body>. Hand it to the page's select-all control, or to the page
 * heading when there is none, so keyboard and screen-reader users keep
 * their place. Focus the user already moved elsewhere is left alone.
 */
export function focusAfterBulk(): void {
    void nextTick(() => {
        const active = document.activeElement;

        if (active !== null && active !== document.body) {
            return;
        }

        const target =
            document.querySelector<HTMLElement>(
                '[data-bulk-select-all] button:not([disabled])',
            ) ?? document.querySelector<HTMLElement>('h1');

        if (target === null) {
            return;
        }

        if (target.tagName === 'H1' && !target.hasAttribute('tabindex')) {
            target.setAttribute('tabindex', '-1');
        }

        target.focus();
    });
}
