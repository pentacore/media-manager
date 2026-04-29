<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        data: number[];
        width?: number;
        height?: number;
    }>(),
    {
        width: 92,
        height: 28,
    },
);

const paths = computed(() => {
    if (props.data.length < 2) {
        return { line: '', area: '', flat: true };
    }

    const max = Math.max(...props.data);
    const min = Math.min(...props.data);

    if (max === min) {
        return { line: '', area: '', flat: true };
    }

    const span = max - min;
    const step = props.width / (props.data.length - 1);
    const points = props.data.map<[number, number]>((v, i) => [
        i * step,
        props.height - ((v - min) / span) * (props.height - 4) - 2,
    ]);
    const line = points
        .map(([x, y], i) =>
            i
                ? `L${x.toFixed(1)} ${y.toFixed(1)}`
                : `M${x.toFixed(1)} ${y.toFixed(1)}`,
        )
        .join(' ');
    const area = `${line} L${props.width} ${props.height} L0 ${props.height} Z`;

    return { line, area, flat: false };
});
</script>

<template>
    <svg
        :width="width"
        :height="height"
        :viewBox="`0 0 ${width} ${height}`"
        class="overflow-visible"
        aria-hidden="true"
    >
        <template v-if="paths.flat">
            <line
                :x1="0"
                :x2="width"
                :y1="height / 2"
                :y2="height / 2"
                class="stroke-border"
                stroke-width="1"
                stroke-dasharray="2 3"
            />
        </template>
        <template v-else>
            <path :d="paths.area" class="fill-accent/15 stroke-none" />
            <path
                :d="paths.line"
                class="fill-none stroke-accent"
                stroke-width="1.5"
            />
        </template>
    </svg>
</template>
