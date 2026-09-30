import { usePage } from '@inertiajs/vue3';
import type { Ability } from '@/types';

export type UseCanReturn = {
    can: (ability: Ability) => boolean;
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

    return { can };
}
