const PAGE_HEADER_MORE_SELECTOR = '[data-page-header-more]';

/**
 * A dialog's `@close-auto-focus` handler for a dialog that a ⋯ menu can open
 * on a phone while its own trigger is a desktop-only button. Below `sm` that
 * trigger has no box, so Reka's focus restore to it is a no-op and focus
 * drops to `<body>`. When the trigger has no box (or there is none), focus
 * goes back to the ⋯ button (`moreSelector`) the user came from; when it has
 * one (desktop), Reka's default restore is left alone.
 */
export function returnFocusToMoreButton(
    event: Event,
    triggerSelector: string,
    moreSelector: string,
): void {
    const trigger = document.querySelector<HTMLElement>(triggerSelector);

    if (trigger !== null && trigger.getClientRects().length > 0) {
        return;
    }

    const more = document.querySelector<HTMLElement>(moreSelector);

    if (more === null || more.getClientRects().length === 0) {
        return;
    }

    event.preventDefault();
    more.focus({ preventScroll: true });
}

/**
 * `returnFocusToMoreButton` for a dialog that a `PageHeader` ⋯ menu item can
 * open: the trigger sits in the header's `hidden sm:contents` secondary
 * actions, and focus returns to `[data-page-header-more]`.
 */
export function returnFocusToPageHeaderMenu(
    event: Event,
    triggerSelector: string,
): void {
    returnFocusToMoreButton(event, triggerSelector, PAGE_HEADER_MORE_SELECTOR);
}
