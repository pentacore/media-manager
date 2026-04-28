<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const page = usePage();
const version = computed(() => {
    const v = (page.props as unknown as { app?: { version?: string } }).app
        ?.version;

    return v ?? '';
});

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
        <span class="font-mono-tabular truncate text-[10.5px] text-fg-subtle">
            <template v-if="version">v{{ version }} · </template>{{ host }}
        </span>
    </div>
</template>
