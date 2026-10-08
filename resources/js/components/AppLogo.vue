<script setup lang="ts">
import { onMounted, ref } from 'vue';

const host = ref('');
onMounted(() => {
    host.value = window.location.hostname;
});
</script>

<template>
    <div
        class="flex aspect-square size-7 items-center justify-center rounded-lg bg-accent font-serif text-base leading-none font-semibold text-accent-foreground italic"
    >
        m
    </div>
    <div class="ml-1 grid flex-1 text-left leading-tight">
        <span class="truncate text-sm font-semibold">MediaManager</span>
        <span
            class="font-mono-tabular truncate text-[10.5px] text-fg-subtle"
            data-app-logo-subtitle
        >
            {{ host }}
        </span>
    </div>
</template>
