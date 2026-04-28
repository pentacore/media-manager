<script setup lang="ts">
import Sparkline from '@/components/mm/Sparkline.vue';

withDefaults(
    defineProps<{
        label: string;
        value: string | number;
        hint?: string;
        spark?: number[];
    }>(),
    {
        hint: undefined,
        spark: undefined,
    },
);
</script>

<template>
    <div
        class="flex min-h-[110px] flex-col gap-2.5 rounded-xl border border-border bg-card p-5"
    >
        <div class="flex items-center justify-between">
            <span
                class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                >{{ label }}</span
            >
            <slot name="accent" />
        </div>
        <div class="flex items-end justify-between gap-3">
            <div>
                <div
                    class="text-[28px] leading-none font-semibold tracking-tight tabular-nums"
                >
                    {{ value }}
                </div>
                <div v-if="hint" class="mt-1 text-xs text-muted-foreground">
                    {{ hint }}
                </div>
            </div>
            <Sparkline v-if="spark" :data="spark" />
        </div>
    </div>
</template>
