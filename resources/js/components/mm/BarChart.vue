<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        data: Array<{ label: string; value: number }>;
        height?: number;
    }>(),
    {
        height: 120,
    },
);

const max = computed(() =>
    props.data.reduce((acc, point) => Math.max(acc, point.value), 0),
);

const showLabels = computed(() => props.data.length <= 20);

const bars = computed(() => {
    const count = props.data.length;

    if (count === 0) {
        return [];
    }

    const gap = 2;
    const slot = 100 / count;
    const barWidth = Math.max(slot - gap, 0.5);

    return props.data.map((point, index) => {
        const ratio = max.value > 0 ? point.value / max.value : 0;
        const heightPct = ratio * 100;

        return {
            key: `${point.label}-${index}`,
            label: point.label,
            value: point.value,
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
                class="fill-accent/70 transition-colors hover:fill-accent"
            >
                <title>{{ bar.label }}: {{ bar.value }}</title>
            </rect>
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
            class="mt-1 flex justify-between text-xs text-muted-foreground"
        >
            <template v-if="showLabels">
                <span
                    v-for="bar in bars"
                    :key="`lbl-${bar.key}`"
                    class="truncate"
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
