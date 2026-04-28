<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        hint: string;
        size?: 'sm' | 'md' | 'lg' | 'xl' | 'full';
    }>(),
    {
        size: 'md',
    },
);

const widthClass = computed(() => {
    switch (props.size) {
        case 'sm':
            return 'w-9';
        case 'md':
            return 'w-14';
        case 'lg':
            return 'w-[110px]';
        case 'xl':
            return 'w-[120px]';
        case 'full':
            return 'w-full';
        default:
            return 'w-14';
    }
});

const hue = computed(
    () =>
        [...(props.hint || 'media')].reduce((a, c) => a + c.charCodeAt(0), 0) %
        360,
);

const styleVars = computed(() => ({
    background: `repeating-linear-gradient(135deg, oklch(0.34 0.06 ${hue.value}) 0 6px, oklch(0.30 0.05 ${hue.value}) 6px 12px)`,
    color: `oklch(0.78 0.06 ${hue.value})`,
}));
</script>

<template>
    <div
        :class="[
            'relative flex aspect-[2/3] items-end overflow-hidden rounded-md border border-border p-2 font-mono text-[10px]',
            widthClass,
        ]"
        :style="styleVars"
    >
        <span class="absolute top-2 right-2 left-2 truncate opacity-60">{{
            hint
        }}</span>
        <span class="font-serif text-[14px] text-foreground italic"
            >poster</span
        >
    </div>
</template>
