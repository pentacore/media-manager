export function formatNumber(value: number | string): string {
    const n = typeof value === 'string' ? parseFloat(value) : value;

    return n.toLocaleString('en-US');
}

export function formatCost(value: string | number): string {
    const n = typeof value === 'string' ? parseFloat(value) : value;

    if (n < 0.01 && n > 0) {
        return `$${n.toFixed(5)}`;
    }

    return `$${n.toFixed(2)}`;
}

export function costDelta(
    actual: string,
    projected: string | undefined,
): string {
    if (projected === undefined) {
        return '';
    }

    const a = parseFloat(actual);
    const p = parseFloat(projected);
    const diff = p - a;
    const sign = diff > 0 ? '+' : '';

    return `${sign}${formatCost(Math.abs(diff))}${diff > 0 ? ' more' : diff < 0 ? ' less' : ''}`;
}

export function formatRate(value: number): string {
    return `$${value.toFixed(4)}`;
}

export function formatMs(value: number | null): string {
    return value === null ? '—' : `${formatNumber(value)} ms`;
}

export function shortClass(value: string | null): string {
    return value?.split('\\').pop() ?? '—';
}

export function formatTimestamp(value: string): string {
    // The ledger SELECTs created_at as a raw timestamp without timezone
    // info, so JS would otherwise interpret it as local time. Append 'Z'
    // when the string has no TZ designator so it is parsed as UTC and the
    // toLocale* calls below convert to the viewer's local timezone.
    const hasTz = /Z$|[+-]\d{2}:?\d{2}$/.test(value);
    const d = new Date(hasTz ? value : `${value.replace(' ', 'T')}Z`);
    const today = new Date();
    const sameDay =
        d.getFullYear() === today.getFullYear() &&
        d.getMonth() === today.getMonth() &&
        d.getDate() === today.getDate();
    const time = d.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });

    if (sameDay) {
        return time;
    }

    const date = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

    return `${date} ${time}`;
}
