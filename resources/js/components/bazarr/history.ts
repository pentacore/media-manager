/**
 * Bazarr's history `action` codes (its own `HistoryAction` enum), as the
 * labels this page shows. An unknown or missing code reads "Updated".
 */
const HISTORY_ACTION_LABELS: Record<number, string> = {
    0: 'Deleted',
    1: 'Downloaded',
    2: 'Manually downloaded',
    3: 'Upgraded',
    4: 'Uploaded',
    5: 'Synced',
    6: 'Translated',
    7: 'Extracted',
};

export function historyActionLabel(action: number | null | undefined): string {
    return (
        (action !== null && action !== undefined
            ? HISTORY_ACTION_LABELS[action]
            : undefined) ?? 'Updated'
    );
}

/**
 * The mapper sends an ISO 8601 time when it could read Bazarr's timestamp
 * and Bazarr's own text otherwise; only the former is ours to format.
 */
export function isIsoTimestamp(
    value: string | null | undefined,
): value is string {
    return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T/.test(value);
}
