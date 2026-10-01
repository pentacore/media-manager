import { usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';

export type UseWhisparrBlurReturn = {
    blurPosters: ComputedRef<boolean>;
};

/**
 * The viewer's "blur Whisparr posters" preference (on unless they turned it
 * off). Every poster on the Whisparr pages reads it.
 */
export function useWhisparrBlur(): UseWhisparrBlurReturn {
    const page = usePage();

    const blurPosters = computed(
        () =>
            page.props.auth.user?.preferences?.whisparr_blur_posters !== false,
    );

    return { blurPosters };
}
