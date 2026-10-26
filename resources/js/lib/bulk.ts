import { nextTick } from 'vue';
import { toast } from 'vue-sonner';
import { jsonRequest } from '@/lib/http';
import { returnFocusToMoreButton } from '@/lib/pageHeader';
import type { BulkSummary } from '@/types';

/** The most ids one bulk request takes (`BulkRunner::MAX_ITEMS` on the server). */
export const BULK_MAX_ITEMS = 100;

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

const DIALOG_SELECTOR = '[role="dialog"], [role="alertdialog"]';

/** About two seconds at 60 fps: longer than any dialog exit animation. */
const MAX_DIALOG_WAIT_FRAMES = 120;

/**
 * After a successful run the selection clears and the bulk bar (holding the
 * focused button, or a dialog's return-focus target) unmounts, dropping
 * focus to <body>. Hand it to the page's select-all control, or to the page
 * heading when there is none, so keyboard and screen-reader users keep
 * their place. Focus the user already moved elsewhere is left alone.
 *
 * A run confirmed in a dialog resolves while focus is still inside that
 * closing dialog. Wait until the dialog has left the DOM, let Reka's
 * zero-delay focus restore (to a trigger the run may have unmounted) go
 * first, and decide only then.
 */
export function focusAfterBulk(): void {
    void nextTick(() => {
        const dialog = document.activeElement?.closest(DIALOG_SELECTOR) ?? null;

        if (dialog === null) {
            moveFocusToBulkTarget();

            return;
        }

        waitForDialogToLeave(dialog, 0);
    });
}

function waitForDialogToLeave(dialog: Element, frame: number): void {
    if (dialog.isConnected && frame < MAX_DIALOG_WAIT_FRAMES) {
        requestAnimationFrame(() => waitForDialogToLeave(dialog, frame + 1));

        return;
    }

    window.setTimeout(moveFocusToBulkTarget, 0);
}

/**
 * A select-all with no box (inside a table hidden on a phone) cannot take
 * focus, so it is skipped and the page heading stands in for it.
 */
function moveFocusToBulkTarget(): void {
    const active = document.activeElement;

    if (active !== null && active !== document.body) {
        return;
    }

    const selectAll = Array.from(
        document.querySelectorAll<HTMLElement>(
            '[data-bulk-select-all] button:not([disabled])',
        ),
    ).find((element) => element.getClientRects().length > 0);

    if (selectAll === undefined) {
        focusPageHeading();

        return;
    }

    selectAll.focus();
}

/**
 * Focuses the page's `h1`, made programmatically focusable first: where focus
 * goes when the control it belongs on is gone. It never scrolls: the heading
 * is a focus anchor, and jumping a phone user to the top of a long list would
 * lose their place.
 */
export function focusPageHeading(options?: FocusOptions): void {
    const heading = document.querySelector<HTMLElement>('h1');

    if (heading === null) {
        return;
    }

    if (!heading.hasAttribute('tabindex')) {
        heading.setAttribute('tabindex', '-1');
    }

    heading.focus({ ...options, preventScroll: true });
}

/**
 * `@close-auto-focus` for a dialog the compact bulk bar's ⋯ menu (or its
 * phone primary action) opens: on a phone, Cancel or Escape returns focus to
 * the bar's ⋯ (`[data-bulk-more]`) instead of `<body>`. `triggerSelector` is
 * the desktop inline button; while it has a box, nothing changes. A run that
 * succeeded unmounts the bar, and `focusAfterBulk()` takes over.
 */
export function returnFocusToBulkMenu(
    event: Event,
    triggerSelector: string,
): void {
    returnFocusToMoreButton(event, triggerSelector, '[data-bulk-more]');
}
