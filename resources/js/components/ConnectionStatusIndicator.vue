<script setup lang="ts">
import { computed } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useConnectionState } from '@/composables/useConnectionState';

const { state } = useConnectionState();

const dotClass = computed(() => {
    switch (state.value) {
        case 'connected':
            return 'bg-green-500 dark:bg-green-400';
        case 'connecting':
            return 'bg-amber-500 dark:bg-amber-400 animate-pulse';
        case 'unavailable':
        case 'disconnected':
        default:
            return 'bg-destructive';
    }
});

const label = computed(() => {
    switch (state.value) {
        case 'connected':
            return 'Realtime connected';
        case 'connecting':
            return 'Realtime connecting…';
        case 'unavailable':
            return 'Realtime unavailable';
        case 'disconnected':
        default:
            return 'Realtime disconnected';
    }
});
</script>

<template>
    <TooltipProvider :delay-duration="200">
        <Tooltip>
            <TooltipTrigger as-child>
                <span
                    class="inline-flex items-center gap-1.5 px-1.5 text-xs text-muted-foreground"
                    :aria-label="label"
                >
                    <span
                        class="size-2 rounded-full"
                        :class="dotClass"
                        aria-hidden="true"
                    />
                </span>
            </TooltipTrigger>
            <TooltipContent>{{ label }}</TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
