import type { ComputedRef, Ref } from 'vue';
import { computed, ref } from 'vue';

const STORAGE_KEY = 'mm.ai-chat.width';

export const DEFAULT_AI_CHAT_WIDTH = 560;
export const MIN_AI_CHAT_WIDTH = 380;
export const MAX_AI_CHAT_WIDTH = 1100;

/** Space always left between the panel's left edge and the viewport edge. */
const VIEWPORT_MARGIN = 96;

/** Pixels added or removed per arrow-key press on the resize handle. */
const KEYBOARD_STEP = 32;

const width = ref(DEFAULT_AI_CHAT_WIDTH);
const resizing = ref(false);
const viewportWidth = ref(0);

let initialized = false;

function readStoredWidth(): number | null {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);

        if (raw === null) {
            return null;
        }

        const parsed = Number.parseInt(raw, 10);

        return Number.isFinite(parsed) ? parsed : null;
    } catch {
        // localStorage disabled — fall back to the default width.
        return null;
    }
}

function persistWidth(value: number): void {
    try {
        localStorage.setItem(STORAGE_KEY, String(value));
    } catch {
        // localStorage full or disabled — the in-memory width still applies.
    }
}

function upperBound(): number {
    if (viewportWidth.value === 0) {
        return MAX_AI_CHAT_WIDTH;
    }

    return Math.max(
        MIN_AI_CHAT_WIDTH,
        Math.min(MAX_AI_CHAT_WIDTH, viewportWidth.value - VIEWPORT_MARGIN),
    );
}

function clampWidth(value: number): number {
    return Math.round(
        Math.min(Math.max(value, MIN_AI_CHAT_WIDTH), upperBound()),
    );
}

function ensureInitialized(): void {
    if (initialized || typeof window === 'undefined') {
        return;
    }

    initialized = true;
    viewportWidth.value = window.innerWidth;
    width.value = clampWidth(readStoredWidth() ?? DEFAULT_AI_CHAT_WIDTH);

    window.addEventListener('resize', () => {
        viewportWidth.value = window.innerWidth;
        // Re-clamp in memory only, so a narrow viewport doesn't overwrite the
        // width the user picked on a larger screen.
        width.value = clampWidth(width.value);
    });
}

export type UseAiChatWidthReturn = {
    width: Ref<number>;
    resizing: Ref<boolean>;
    minWidth: number;
    maxWidth: ComputedRef<number>;
    setWidth: (value: number) => void;
    nudgeWidth: (delta: number) => void;
    resetWidth: () => void;
    startResize: (event: PointerEvent) => void;
    handleKeydown: (event: KeyboardEvent) => void;
};

/**
 * Persisted, drag-resizable width for the AI assistant side panel.
 */
export function useAiChatWidth(): UseAiChatWidthReturn {
    ensureInitialized();

    const setWidth = (value: number): void => {
        width.value = clampWidth(value);
        persistWidth(width.value);
    };

    const nudgeWidth = (delta: number): void => {
        setWidth(width.value + delta);
    };

    const resetWidth = (): void => {
        setWidth(DEFAULT_AI_CHAT_WIDTH);
    };

    /**
     * Track the pointer against the panel's right-anchored edge, keeping the
     * grab offset so the handle doesn't jump under the cursor.
     */
    const startResize = (event: PointerEvent): void => {
        if (typeof window === 'undefined' || event.button !== 0) {
            return;
        }

        event.preventDefault();
        resizing.value = true;

        const grabOffset = width.value - (window.innerWidth - event.clientX);

        const onPointerMove = (move: PointerEvent): void => {
            setWidth(window.innerWidth - move.clientX + grabOffset);
        };

        const onPointerUp = (): void => {
            resizing.value = false;
            window.removeEventListener('pointermove', onPointerMove);
            window.removeEventListener('pointerup', onPointerUp);
            window.removeEventListener('pointercancel', onPointerUp);
            document.body.style.removeProperty('user-select');
        };

        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);
        window.addEventListener('pointercancel', onPointerUp);
        document.body.style.setProperty('user-select', 'none');
    };

    const handleKeydown = (event: KeyboardEvent): void => {
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            nudgeWidth(KEYBOARD_STEP);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            nudgeWidth(-KEYBOARD_STEP);
        } else if (event.key === 'Home') {
            event.preventDefault();
            resetWidth();
        }
    };

    return {
        width,
        resizing,
        minWidth: MIN_AI_CHAT_WIDTH,
        maxWidth: computed(upperBound),
        setWidth,
        nudgeWidth,
        resetWidth,
        startResize,
        handleKeydown,
    };
}
