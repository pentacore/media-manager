/**
 * A host's uptime as its two largest units: "3d 4h", "5h 12m", "42m".
 * Missing or negative is "—".
 */
export function formatUptime(seconds: number | null | undefined): string {
    if (
        typeof seconds !== 'number' ||
        !Number.isFinite(seconds) ||
        seconds < 0
    ) {
        return '—';
    }

    const days = Math.floor(seconds / 86_400);
    const hours = Math.floor((seconds % 86_400) / 3_600);
    const minutes = Math.floor((seconds % 3_600) / 60);

    if (days > 0) {
        return `${days}d ${hours}h`;
    }

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }

    return `${minutes}m`;
}

/** A percentage with one decimal, "—" when missing. */
export function formatPercent(value: number | null | undefined): string {
    return typeof value === 'number' && Number.isFinite(value)
        ? `${value.toFixed(1)}%`
        : '—';
}
