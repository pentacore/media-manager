import { useDateTime } from '@/composables/useDateTime';

export interface SeriesPoint {
    bucket: string;
    count: number;
    sum: number | null;
}

export interface BarPoint {
    label: string;
    value: number;
}

const HOUR_MS = 3_600_000;

/**
 * Shared helpers for the statistics pages: turn gap-padded rollup series
 * from StatisticsRepository into BarChart data, with bucket labels that
 * honour the user's timezone preference and include the hour whenever the
 * backend resolved an hour-granularity window (≤ 7d) — day-only labels
 * would render every bar of the same day identically.
 */
export function useStatisticsSeries() {
    const { preferences } = useDateTime();

    function isHourly(series: SeriesPoint[]): boolean {
        if (series.length < 2) {
            return false;
        }

        const first = new Date(series[0].bucket).getTime();
        const second = new Date(series[1].bucket).getTime();

        return second - first <= HOUR_MS;
    }

    function bucketLabel(bucket: string, hourly: boolean): string {
        const date = new Date(bucket);

        if (Number.isNaN(date.getTime())) {
            return bucket;
        }

        return new Intl.DateTimeFormat(undefined, {
            timeZone: preferences.value.timezone,
            month: 'short',
            day: 'numeric',
            ...(hourly
                ? { hour: '2-digit' as const, minute: '2-digit' as const }
                : {}),
        }).format(date);
    }

    function toBarData(series: SeriesPoint[]): BarPoint[] {
        const hourly = isHourly(series);

        return series.map((point) => ({
            label: bucketLabel(point.bucket, hourly),
            value: point.count,
        }));
    }

    /**
     * A gauge series stores a sample count in `count` and the summed value
     * in `sum`; the display value is the per-bucket average.
     */
    function toAvgBarData(series: SeriesPoint[]): BarPoint[] {
        const hourly = isHourly(series);

        return series.map((point) => ({
            label: bucketLabel(point.bucket, hourly),
            value: point.count > 0 ? (point.sum ?? 0) / point.count : 0,
        }));
    }

    /**
     * Sum-valued series (e.g. AI cost in USD) rendered as bars.
     */
    function toSumBarData(series: SeriesPoint[]): BarPoint[] {
        const hourly = isHourly(series);

        return series.map((point) => ({
            label: bucketLabel(point.bucket, hourly),
            value: point.sum ?? 0,
        }));
    }

    return { toBarData, toAvgBarData, toSumBarData };
}
