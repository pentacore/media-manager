<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import StatisticsController from '@/actions/App/Http/Controllers/StatisticsController';
import {
    BarChart,
    Heatmap,
    Poster,
    StatCard,
    TimeWindowFilter,
} from '@/components/mm';
import { dashboard } from '@/routes';

interface SeriesPoint {
    bucket: string;
    count: number;
    sum: number | null;
}

const props = defineProps<{
    window: string;
    windows: { value: string; label: string }[];
    headline: {
        plays: number;
        finishes: number;
        watchHours: number;
        downloads: number;
    };
    watchSeries: SeriesPoint[];
    downloadSeries: SeriesPoint[];
    librarySeries: SeriesPoint[];
    requestFunnel: {
        created: number;
        approved: number;
        declined: number;
        available: number;
    };
    leaderboard?: { user: string; plays: number; seconds: number }[];
    topTitles?: { title: string; media_type: string; plays: number }[];
    hourHeatmap?: Record<string, Record<string, number>>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Overview', href: dashboard().url },
            { title: 'Statistics', href: StatisticsController.url() },
        ],
    },
});

function setWindow(value: string): void {
    router.visit(StatisticsController.url({ query: { window: value } }), {
        preserveScroll: true,
        preserveState: true,
    });
}

function bucketLabel(bucket: string): string {
    const date = new Date(bucket);

    if (Number.isNaN(date.getTime())) {
        return bucket;
    }

    return date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}

function toBarData(series: SeriesPoint[]): { label: string; value: number }[] {
    return series.map((point) => ({
        label: bucketLabel(point.bucket),
        value: point.count,
    }));
}

const watchBars = computed(() => toBarData(props.watchSeries));
const libraryBars = computed(() => toBarData(props.librarySeries));

const funnelStages = computed(() => {
    const created = Math.max(props.requestFunnel.created, 1);

    return [
        { label: 'Created', value: props.requestFunnel.created },
        { label: 'Approved', value: props.requestFunnel.approved },
        { label: 'Available', value: props.requestFunnel.available },
        { label: 'Declined', value: props.requestFunnel.declined },
    ].map((stage) => ({
        ...stage,
        pct: Math.round((stage.value / created) * 100),
    }));
});

const heatmapRowLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const heatmapColLabels = Array.from({ length: 24 }, (_, hour) => String(hour));

/**
 * The repository returns a 1..7 (ISO weekday) keyed map of 0..23 hour maps.
 * Convert it to a Mon..Sun ordered 7×24 matrix for display.
 */
function heatmapMatrix(
    source: Record<string, Record<string, number>> | undefined,
): number[][] {
    return [1, 2, 3, 4, 5, 6, 7].map((weekday) => {
        const hours = source?.[String(weekday)] ?? {};

        return Array.from(
            { length: 24 },
            (_, hour) => hours[String(hour)] ?? 0,
        );
    });
}

function formatHours(seconds: number): string {
    return `${(seconds / 3600).toFixed(1)}h`;
}
</script>

<template>
    <Head title="Statistics" />

    <div class="space-y-6 p-6">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Statistics</h1>
            <TimeWindowFilter
                :options="windows"
                :model-value="window"
                @update:model-value="setWindow"
            />
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
                label="Plays"
                :value="headline.plays"
                :spark="watchSeries.map((p) => p.count)"
            />
            <StatCard label="Finished" :value="headline.finishes" />
            <StatCard label="Hours watched" :value="headline.watchHours" />
            <StatCard
                label="Downloads"
                :value="headline.downloads"
                :spark="downloadSeries.map((p) => p.count)"
            />
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="mb-4 text-sm font-semibold">Plays over time</h2>
                <BarChart :data="watchBars" :height="160" />
            </div>
            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="mb-4 text-sm font-semibold">
                    Library size (movies)
                </h2>
                <BarChart :data="libraryBars" :height="160" />
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-5">
            <h2 class="mb-4 text-sm font-semibold">Request funnel</h2>
            <div class="space-y-3">
                <div v-for="stage in funnelStages" :key="stage.label">
                    <div class="mb-1 flex items-center justify-between text-xs">
                        <span class="text-muted-foreground">{{
                            stage.label
                        }}</span>
                        <span class="tabular-nums">{{ stage.value }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full rounded-full bg-accent"
                            :style="{ width: `${stage.pct}%` }"
                        />
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <Deferred data="leaderboard">
                <template #fallback>
                    <div class="h-56 animate-pulse rounded-xl bg-muted" />
                </template>
                <div class="rounded-xl border border-border bg-card p-5">
                    <h2 class="mb-4 text-sm font-semibold">
                        Watch leaderboard
                    </h2>
                    <table
                        v-if="leaderboard && leaderboard.length"
                        class="w-full text-sm"
                    >
                        <thead>
                            <tr
                                class="text-left text-xs text-muted-foreground uppercase"
                            >
                                <th class="pb-2 font-medium">User</th>
                                <th class="pb-2 text-right font-medium">
                                    Plays
                                </th>
                                <th class="pb-2 text-right font-medium">
                                    Watched
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in leaderboard"
                                :key="row.user"
                                class="border-t border-border"
                            >
                                <td class="py-2">{{ row.user }}</td>
                                <td class="py-2 text-right tabular-nums">
                                    {{ row.plays }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{ formatHours(row.seconds) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="text-sm text-muted-foreground">
                        No watch activity in this window.
                    </p>
                </div>
            </Deferred>

            <Deferred data="topTitles">
                <template #fallback>
                    <div class="h-56 animate-pulse rounded-xl bg-muted" />
                </template>
                <div class="rounded-xl border border-border bg-card p-5">
                    <h2 class="mb-4 text-sm font-semibold">Top titles</h2>
                    <ul v-if="topTitles && topTitles.length" class="space-y-3">
                        <li
                            v-for="title in topTitles"
                            :key="`${title.title}-${title.media_type}`"
                            class="flex items-center gap-3"
                        >
                            <Poster :hint="title.title" size="sm" />
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium">
                                    {{ title.title }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ title.media_type }}
                                </div>
                            </div>
                            <span class="text-sm tabular-nums"
                                >{{ title.plays }} plays</span
                            >
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        No plays in this window.
                    </p>
                </div>
            </Deferred>
        </div>

        <Deferred data="hourHeatmap">
            <template #fallback>
                <div class="h-56 animate-pulse rounded-xl bg-muted" />
            </template>
            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="mb-4 text-sm font-semibold">Watch heatmap</h2>
                <Heatmap
                    :data="heatmapMatrix(hourHeatmap)"
                    :row-labels="heatmapRowLabels"
                    :col-labels="heatmapColLabels"
                />
            </div>
        </Deferred>
    </div>
</template>
