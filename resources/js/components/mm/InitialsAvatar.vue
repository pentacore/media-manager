<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        name: string;
        size?: number;
    }>(),
    {
        size: 22,
    },
);

const initials = computed(() =>
    (props.name || '?')
        .split(/[\s.@_-]+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((s) => s[0]?.toUpperCase() ?? '')
        .join(''),
);

const hue = computed(
    () =>
        [...(props.name || '?')].reduce((a, c) => a + c.charCodeAt(0), 0) % 360,
);

const style = computed(() => ({
    width: `${props.size}px`,
    height: `${props.size}px`,
    background: `oklch(0.36 0.06 ${hue.value})`,
    color: `oklch(0.92 0.04 ${hue.value})`,
    fontSize: `${Math.round(props.size * 0.42)}px`,
}));
</script>

<template>
    <span
        class="inline-flex flex-none items-center justify-center rounded-full border border-border font-semibold tracking-wide select-none"
        :style="style"
    >
        {{ initials }}
    </span>
</template>
