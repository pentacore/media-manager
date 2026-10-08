import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import type { Ability } from '@/types';

export type UseCanReturn = {
    can: (ability: Ability) => boolean;
    /** Whether the current user holds the `admin` ability. */
    isAdmin: ComputedRef<boolean>;
};

/**
 * Reads the server-computed `auth.can` map. Call `can()` inside a computed or
 * the template so it re-evaluates when shared props change.
 */
export function useCan(): UseCanReturn {
    const page = usePage();

    function can(ability: Ability): boolean {
        return page.props.auth.can?.[ability] === true;
    }

    const isAdmin = computed(() => can('admin'));

    return { can, isAdmin };
}
