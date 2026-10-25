import type { Ref } from 'vue';
import { onMounted, onUnmounted, ref } from 'vue';

export type UseVisualViewportReturn = {
    /** The visible viewport's height in CSS px; null before mount or without the API. */
    height: Ref<number | null>;
    /** How far the visible viewport sits below the layout viewport's top. */
    offsetTop: Ref<number>;
};

/**
 * The visual viewport: the part of the page the user can see. On a phone the
 * on-screen keyboard shrinks it while the layout viewport (`100vh`,
 * `inset-0`) stays the full screen, so anything pinned to the bottom of a
 * full-height overlay hides behind the keyboard unless it follows this.
 * Client-only: SSR leaves `height` null.
 */
export function useVisualViewport(): UseVisualViewportReturn {
    const height = ref<number | null>(null);
    const offsetTop = ref(0);
    let viewport: VisualViewport | null = null;

    function read(): void {
        if (viewport !== null) {
            height.value = viewport.height;
            offsetTop.value = viewport.offsetTop;
        }
    }

    onMounted(() => {
        viewport = window.visualViewport ?? null;
        read();
        viewport?.addEventListener('resize', read);
        viewport?.addEventListener('scroll', read);
    });

    onUnmounted(() => {
        viewport?.removeEventListener('resize', read);
        viewport?.removeEventListener('scroll', read);
        viewport = null;
    });

    return { height, offsetTop };
}
