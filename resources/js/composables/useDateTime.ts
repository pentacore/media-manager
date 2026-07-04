import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { DateFormat, UserPreferences } from '@/types/preferences';

const FALLBACK: UserPreferences = {
    time_format: '24h',
    date_format: 'iso',
    timezone: 'UTC',
    first_day_of_week: 1,
    show_relative_time: true,
};

/**
 * Centralised date/time formatter that honours the signed-in user's
 * display preferences (set under /settings/preferences). Always pass an
 * ISO 8601 string from the backend; the composable handles parsing,
 * timezone conversion, and locale-aware formatting via Intl.
 */
export function useDateTime() {
    const page = usePage();

    const prefs = computed<UserPreferences>(
        () => page.props.auth?.user?.preferences ?? FALLBACK,
    );

    function localeForDateFormat(format: DateFormat): string {
        switch (format) {
            case 'us':
                return 'en-US';
            case 'eu':
                return 'de-DE';
            case 'long':
                return 'en-GB';
            case 'iso':
            default:
                return 'sv-SE';
        }
    }

    function parse(iso: string | null | undefined): Date | null {
        if (!iso) {
            return null;
        }

        const date = new Date(iso);

        return Number.isNaN(date.getTime()) ? null : date;
    }

    function formatDate(iso: string | null | undefined): string {
        const date = parse(iso);

        if (!date) {
            return '—';
        }

        const { date_format, timezone } = prefs.value;
        const locale = localeForDateFormat(date_format);

        const options: Intl.DateTimeFormatOptions = {
            timeZone: timezone,
            year: 'numeric',
            month: date_format === 'long' ? 'short' : '2-digit',
            day: date_format === 'long' ? 'numeric' : '2-digit',
        };

        return new Intl.DateTimeFormat(locale, options).format(date);
    }

    function formatTime(iso: string | null | undefined): string {
        const date = parse(iso);

        if (!date) {
            return '—';
        }

        const { time_format, timezone } = prefs.value;

        return new Intl.DateTimeFormat('en-GB', {
            timeZone: timezone,
            // Unpadded hour for 12h reads more naturally ("1:30 PM"); pad
            // for 24h to keep tabular columns aligned.
            hour: time_format === '12h' ? 'numeric' : '2-digit',
            minute: '2-digit',
            hour12: time_format === '12h',
        }).format(date);
    }

    function formatDateTime(iso: string | null | undefined): string {
        const date = parse(iso);

        if (!date) {
            return '—';
        }

        return `${formatDate(iso)} ${formatTime(iso)}`;
    }

    /**
     * Smart timestamp: relative phrasing for anything within the last
     * week when the user opts in, otherwise an absolute formatted
     * datetime. Buckets: "just now" → "Xm ago" → "Xh ago" → "Xd ago"
     * (up to 6 days), then absolute.
     */
    function formatSmart(iso: string | null | undefined): string {
        const date = parse(iso);

        if (!date) {
            return '—';
        }

        if (!prefs.value.show_relative_time) {
            return formatDateTime(iso);
        }

        const ms = Date.now() - date.getTime();

        if (ms < 0) {
            return formatDateTime(iso);
        }

        const m = Math.floor(ms / 60_000);

        if (m < 1) {
            return 'just now';
        }

        if (m < 60) {
            return `${m}m ago`;
        }

        const h = Math.floor(m / 60);

        if (h < 24) {
            return `${h}h ago`;
        }

        const d = Math.floor(h / 24);

        if (d < 7) {
            return `${d}d ago`;
        }

        return formatDateTime(iso);
    }

    return {
        preferences: prefs,
        formatDate,
        formatTime,
        formatDateTime,
        formatSmart,
    };
}
