<script setup lang="ts">
import { Cog } from '@lucide/vue';
import { computed } from 'vue';
import type { AgentStep } from '@/composables/useAiChat';

const props = defineProps<{
    step: AgentStep | null;
}>();

const label = computed<string | null>(() => {
    if (!props.step) {
        return null;
    }

    return `Calling ${props.step.toolName}…`;
});
</script>

<template>
    <Transition
        enter-active-class="transition-opacity duration-150"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-opacity duration-150"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div
            v-if="label"
            class="flex items-center gap-2 rounded-md border border-border/60 bg-bg-elev px-2.5 py-1.5 text-[12px] text-muted-foreground"
        >
            <Cog class="size-3.5 animate-spin text-accent" />
            <span>{{ label }}</span>
        </div>
    </Transition>
</template>
