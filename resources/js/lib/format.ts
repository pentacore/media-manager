const BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'] as const;

/** Whole numbers up to MB, one decimal from GB up. */
function byteDecimals(unitIndex: number): number {
    return unitIndex >= 3 ? 1 : 0;
}

/**
 * Human-readable size of a byte count in binary steps (1 KB = 1024 B):
 * "734 MB", "1.5 GB", "2.0 TB". Zero is "0 B"; a missing, negative or
 * non-numeric value is "—". A value that would round up to 1024 of its
 * unit is shown in the next unit ("1.0 GB", never "1024 MB").
 */
export function formatBytes(bytes: unknown): string {
    if (typeof bytes !== 'number' || !Number.isFinite(bytes) || bytes < 0) {
        return '—';
    }

    let value = bytes;
    let unitIndex = 0;

    while (
        unitIndex < BYTE_UNITS.length - 1 &&
        Number(value.toFixed(byteDecimals(unitIndex))) >= 1024
    ) {
        value /= 1024;
        unitIndex += 1;
    }

    return `${value.toFixed(byteDecimals(unitIndex))} ${BYTE_UNITS[unitIndex]}`;
}

const COUNT_FORMAT = new Intl.NumberFormat('en-US');

/**
 * A count with en-US grouping: "1,250,000". Fixed to en-US, not the browser
 * locale, so the server render and the hydrated page print the same string.
 * A numeric string is parsed; a missing, non-numeric or non-finite value is
 * "—".
 */
export function formatCount(value: unknown): string {
    const count = typeof value === 'string' ? Number.parseFloat(value) : value;

    if (typeof count !== 'number' || !Number.isFinite(count)) {
        return '—';
    }

    return COUNT_FORMAT.format(count);
}
