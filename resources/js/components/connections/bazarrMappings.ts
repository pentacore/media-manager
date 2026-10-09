import type { FormDataConvertible } from '@inertiajs/core';

/**
 * Select value for "no Sonarr/Radarr connection" in the Bazarr mapping
 * selects. The form submits it as-is and `withBazarrMappingIds()` turns it
 * back into null.
 */
export const NOT_CONNECTED_VALUE = 'not-connected';

/**
 * `<Form :transform>` shared by the connection create and edit forms: maps
 * the "not connected" sentinel of both Bazarr mapping selects to null and
 * passes every other field through unchanged.
 */
export function withBazarrMappingIds(
    data: Record<string, FormDataConvertible>,
): Record<string, FormDataConvertible> {
    return {
        ...data,
        sonarr_connection_id:
            data.sonarr_connection_id === NOT_CONNECTED_VALUE
                ? null
                : data.sonarr_connection_id,
        radarr_connection_id:
            data.radarr_connection_id === NOT_CONNECTED_VALUE
                ? null
                : data.radarr_connection_id,
    };
}
