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

    returnFocusTo(event, document.querySelector<HTMLElement>(moreSelector));
}

/**
 * A dialog's `@close-auto-focus` handler (or `useConfirm`'s
 * `onCloseAutoFocus`) that sends focus to `element` while it is still on the
 * page with a box, in place of Reka's restore. When it is gone or has no box,
 * Reka's default restore runs.
 */
export function returnFocusTo(event: Event, element: HTMLElement | null): void {
    if (
        element === null ||
        !element.isConnected ||
        element.getClientRects().length === 0
    ) {
        return;
    }

    event.preventDefault();
    element.focus({ preventScroll: true });
}

/**
 * The trigger of the dropdown menu that has focus (one of its items, or the
 * menu itself after a touch open): Reka labels the menu by its trigger's id.
 * A row ⋯ menu item that asks with `confirm()` reads it before asking. The
 * app-wide confirm dialog has no trigger of its own and would restore focus
 * to the menu item, which is gone by the time the dialog closes, so focus
 * would drop to `<body>`; pass the trigger to `returnFocusTo` instead.
 */
export function focusedMenuTrigger(): HTMLElement | null {
    const labelledBy = document.activeElement
        ?.closest('[role="menu"]')
        ?.getAttribute('aria-labelledby');

    if (!labelledBy) {
        return null;
    }

    return document.getElementById(labelledBy);
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
