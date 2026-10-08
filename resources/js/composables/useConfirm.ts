import { computed, ref, shallowRef } from 'vue';
import type { ComputedRef } from 'vue';

export type ConfirmOptions = {
    /** The question, e.g. `Delete "Primary Sonarr"?`. */
    title: string;
    /** What confirming does, e.g. "This cannot be undone." */
    description?: string;
    /** The confirm button's label. Defaults to "Confirm". */
    confirmLabel?: string;
    /** The dismiss button's label. Defaults to "Cancel". */
    cancelLabel?: string;
    /** Styles the confirm button as destructive (deletes, removals, blocklists). */
    destructive?: boolean;
};

export type ConfirmRequest = {
    title: string;
    description: string | null;
    confirmLabel: string;
    cancelLabel: string;
    destructive: boolean;
};

export type UseConfirmReturn = {
    /** Asks in the app's confirm dialog; resolves true only when the user confirms. */
    confirm: (options: ConfirmOptions) => Promise<boolean>;
    isOpen: ComputedRef<boolean>;
    request: ComputedRef<ConfirmRequest | null>;
    /** Closes the dialog and answers the pending question. */
    settle: (confirmed: boolean) => void;
};

// Module-level, like useAiChat: one dialog for the whole app. Only click
// handlers call confirm(), so SSR renders never touch this state.
const open = ref(false);
const current = shallowRef<ConfirmRequest | null>(null);
let answer: ((confirmed: boolean) => void) | null = null;

function settle(confirmed: boolean): void {
    const pending = answer;

    answer = null;
    open.value = false;
    pending?.(confirmed);
}

function confirm(options: ConfirmOptions): Promise<boolean> {
    // A new question replaces an unanswered one, which counts as a "no".
    settle(false);
    current.value = {
        title: options.title,
        description: options.description ?? null,
        confirmLabel: options.confirmLabel ?? 'Confirm',
        cancelLabel: options.cancelLabel ?? 'Cancel',
        destructive: options.destructive ?? false,
    };
    open.value = true;

    return new Promise<boolean>((resolve) => {
        answer = resolve;
    });
}

const isOpen = computed(() => open.value);
const request = computed(() => current.value);

/**
 * The app-wide confirm dialog that ConfirmDialog renders once in the app
 * layout. `if (!(await confirm({ ... }))) return;` replaces the browser's
 * blocking confirm().
 */
export function useConfirm(): UseConfirmReturn {
    return { confirm, isOpen, request, settle };
}
