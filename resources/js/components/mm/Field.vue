<script setup lang="ts">
defineProps<{
    label: string;
    hint?: string;
}>();
</script>

<template>
    <div class="grid gap-1.5">
        <div
            class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
        >
            {{ label }}
        </div>
        <div class="text-[13px]">
            <slot />
        </div>
        <div v-if="hint" class="text-[12px] leading-snug text-muted-foreground">
            {{ hint }}
        </div>
    </div>
</template>
