<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { BarChart, SegmentedControl } from '@/components/mm';
import type { BarChartPoint } from '@/components/mm';
import { Skeleton } from '@/components/ui/skeleton';
import { useDateTime } from '@/composables/useDateTime';
import { cn } from '@/lib/utils';
import type {
    EnumOption,
    MetricPoint,
    MetricSeries,
    ServerMetricRange,
} from './types';

const props = defineProps<{
    /** Undefined until the deferred `metrics` prop arrives. */
    metrics: MetricSeries | undefined;
    range: ServerMetricRange;
    ranges: EnumOption<ServerMetricRange>[];
}>();

const selected = ref<ServerMetricRange>(props.range);
const reloading = ref(false);

watch(
    () => props.range,
    (range) => {
        selected.value = range;
    },
);

function selectRange(range: ServerMetricRange): void {
    if (range === selected.value) {
        return;
    }

    let succeeded = false;

    selected.value = range;
    router.reload({
        data: { range },
        only: ['metrics', 'range'],
        onStart: () => {
            reloading.value = true;
        },
        onSuccess: () => {
            succeeded = true;
        },
        onFinish: () => {
            reloading.value = false;

            // A failed or cancelled reload left the old range's charts on screen.
            if (!succeeded) {
                selected.value = props.range;
            }
        },
    });
}

const { formatDate, formatDateTime, formatTime } = useDateTime();

/** Axis labels: the time for 6 h, date and time for 48 h and 30 d, the date for a year. */
function axisLabel(at: string): string {
    switch (props.range) {
        case '6h':
            return formatTime(at);
        case '1y':
            return formatDate(at);
        default:
            return formatDateTime(at);
    }
}

/** Tooltips always carry the date, except on the year chart where the time means nothing. */
function tooltipLabel(at: string): string {
    return props.range === '1y' ? formatDate(at) : formatDateTime(at);
}

function bars(points: MetricPoint[]): BarChartPoint[] {
    return points.map((point) =>
        point.value === null
            ? {
                  label: axisLabel(point.at),
                  value: 0,
                  placeholder: true,
                  tooltip: `${tooltipLabel(point.at)}: no data`,
              }
            : {
                  label: axisLabel(point.at),
                  value: point.value,
                  tooltip: `${tooltipLabel(point.at)}: ${point.value}%`,
              },
    );
}

const charts = computed(() => {
    if (!props.metrics) {
        return [];
    }

    return [
        {
            key: 'cpu',
            title: 'CPU',
            attrs: { 'data-metrics-chart': 'cpu' },
            data: bars(props.metrics.cpu),
        },
        {
            key: 'memory',
            title: 'Memory',
            attrs: { 'data-metrics-chart': 'memory' },
            data: bars(props.metrics.memory),
        },
        ...props.metrics.filesystems.map((filesystem) => ({
            key: `fs:${filesystem.mount}`,
            title: `Disk ${filesystem.mount}`,
            attrs: { 'data-metrics-chart-fs': filesystem.mount },
            data: bars(filesystem.points),
        })),
    ];
});
</script>

<template>
    <section
        class="rounded-xl border border-border bg-card p-5"
        data-metrics-panel
        :aria-busy="metrics === undefined || reloading"
    >
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-sm font-semibold">Usage</h2>
            <SegmentedControl
                :options="ranges"
                :model-value="selected"
                option-attribute="metrics-range"
                @update:model-value="selectRange"
            />
        </div>

        <div
            v-if="metrics === undefined"
            class="grid gap-4 sm:grid-cols-2"
            data-metrics-loading
        >
            <div v-for="index in 2" :key="index" class="space-y-2">
                <Skeleton class="h-4 w-20" />
                <Skeleton class="h-[136px] w-full" />
            </div>
        </div>
        <div
            v-else
            :class="
                cn(
                    'grid gap-x-6 gap-y-5 transition-opacity sm:grid-cols-2',
                    reloading && 'opacity-60',
                )
            "
        >
            <div
                v-for="chart in charts"
                :key="chart.key"
                class="min-w-0"
                v-bind="chart.attrs"
            >
                <h3
                    class="mb-2 truncate text-xs text-muted-foreground"
                    :title="chart.title"
                >
                    {{ chart.title }}
                    <span class="text-fg-subtle">(%)</span>
                </h3>
                <BarChart :data="chart.data" :max="100" :height="120" />
            </div>
        </div>
    </section>
</template>
