const PAGE_HEADER_MORE_SELECTOR = '[data-page-header-more]';

/**
 * A dialog's `@close-auto-focus` handler for a dialog that a `PageHeader` ⋯
 * menu item can open. Below `sm` the dialog's own trigger sits in the
 * header's `hidden sm:contents` secondary actions, so Reka's focus restore
 * to it is a no-op and focus drops to `<body>`. When that trigger has no box
 * (or there is none), focus goes back to the ⋯ button the user came from;
 * when it has one (desktop), Reka's default restore is left alone.
 */
export function returnFocusToPageHeaderMenu(
    event: Event,
    triggerSelector: string,
): void {
    const trigger = document.querySelector<HTMLElement>(triggerSelector);

    if (trigger !== null && trigger.getClientRects().length > 0) {
        return;
    }

    const more = document.querySelector<HTMLElement>(PAGE_HEADER_MORE_SELECTOR);

    if (more === null || more.getClientRects().length === 0) {
        return;
    }

    event.preventDefault();
    more.focus({ preventScroll: true });
}
