<script setup lang="ts">
import { onMounted, ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const host = ref('');
onMounted(() => {
    host.value = window.location.hostname;
});
</script>

<template>
    <!-- Wrapped: the sidebar button forces a direct svg child to size-4. -->
    <span class="flex size-7 shrink-0">
        <AppLogoIcon class="size-full" aria-hidden="true" />
    </span>
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
