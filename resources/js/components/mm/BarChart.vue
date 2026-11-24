<script setup lang="ts">
import { computed } from 'vue';

export interface BarChartPoint {
    label: string;
    value: number;
    /**
     * Renders a muted, dashed placeholder bar instead of plotting `value` —
     * for a data point with nothing resolved yet, where a 0-height bar would
     * read as a confirmed zero rather than "no data".
     */
    placeholder?: boolean;
    /** Overrides the bar's hover tooltip; defaults to "label: value". */
    tooltip?: string;
}

const props = withDefaults(
    defineProps<{
        data: Array<BarChartPoint>;
        height?: number;
        markerAt?: number | null;
        /**
         * Scale every bar against this value instead of the tallest bar in
         * `data`. Use it for charts whose values share a fixed scale (e.g.
         * percentages out of 100) so an empty band doesn't look identical to
         * a full one.
         */
        max?: number | null;
        /**
         * Draw a thin stub for a measured zero (a non-placeholder point whose
         * value is 0) so it isn't an empty slot. Use it where zero is a real
         * reading, such as a 0 % rate, and placeholders mark "no data".
         */
        showZero?: boolean;
    }>(),
    {
        height: 120,
        markerAt: null,
        max: null,
        showZero: false,
    },
);

const scaleMax = computed(
    () =>
        props.max ??
        props.data.reduce((acc, point) => Math.max(acc, point.value), 0),
);

const showLabels = computed(() => props.data.length <= 20);

const PLACEHOLDER_HEIGHT_PCT = 6;

const ZERO_HEIGHT_PCT = 3;

const bars = computed(() => {
    const count = props.data.length;

    if (count === 0) {
        return [];
    }

    const gap = 2;
    const slot = 100 / count;
    const barWidth = Math.max(slot - gap, 0.5);

    return props.data.map((point, index) => {
        const ratio = scaleMax.value > 0 ? point.value / scaleMax.value : 0;
        const zero = props.showZero && !point.placeholder && point.value === 0;
        const heightPct = point.placeholder
            ? PLACEHOLDER_HEIGHT_PCT
            : zero
              ? ZERO_HEIGHT_PCT
              : Math.min(ratio * 100, 100);

        return {
            key: `${point.label}-${index}`,
            label: point.label,
            value: point.value,
            placeholder: point.placeholder ?? false,
            zero,
            tooltip: point.tooltip ?? `${point.label}: ${point.value}`,
            x: index * slot + gap / 2,
            width: barWidth,
            y: 100 - heightPct,
            height: heightPct,
            isEdge: index === 0 || index === count - 1,
        };
    });
});
</script>

<template>
    <div class="w-full">
        <svg
            v-if="bars.length"
            :viewBox="`0 0 100 100`"
            preserveAspectRatio="none"
            :style="{ height: `${height}px`, width: '100%' }"
            class="overflow-visible"
        >
            <rect
                v-for="bar in bars"
                :key="bar.key"
                :x="bar.x"
                :y="bar.y"
                :width="bar.width"
                :height="bar.height"
                rx="0.6"
                :data-bar-placeholder="bar.placeholder ? 'true' : 'false'"
                :data-bar-zero="bar.zero ? 'true' : 'false'"
                :class="
                    bar.placeholder
                        ? 'fill-none stroke-muted-foreground/50 stroke-[1.5] [stroke-dasharray:3,2]'
                        : 'fill-accent/70 transition-colors hover:fill-accent'
                "
                :vector-effect="
                    bar.placeholder ? 'non-scaling-stroke' : undefined
                "
            >
                <title>{{ bar.tooltip }}</title>
            </rect>
            <line
                v-if="markerAt !== null && markerAt !== undefined"
                :x1="markerAt * 100"
                :x2="markerAt * 100"
                y1="0"
                y2="100"
                class="stroke-warning"
                stroke-dasharray="2 2"
                vector-effect="non-scaling-stroke"
                data-bar-chart-marker
            />
        </svg>
        <div
            v-else
            class="flex items-center justify-center"
            :style="{ height: `${height}px` }"
        >
            <div class="w-full border-t border-dashed border-border" />
        </div>

        <div
            v-if="bars.length"
            :class="[
                'mt-1 flex text-xs text-muted-foreground',
                !showLabels && 'justify-between',
            ]"
            data-bar-chart-labels
        >
            <!-- One equal slot per bar, centred like the bar above it, so a
                 caller's own per-bar row (the gate counts) lines up too. -->
            <template v-if="showLabels">
                <span
                    v-for="bar in bars"
                    :key="`lbl-${bar.key}`"
                    class="min-w-0 flex-1 truncate text-center"
                >
                    {{ bar.label }}
                </span>
            </template>
            <template v-else>
                <span>{{ bars[0].label }}</span>
                <span>{{ bars[bars.length - 1].label }}</span>
            </template>
        </div>
    </div>
</template>
