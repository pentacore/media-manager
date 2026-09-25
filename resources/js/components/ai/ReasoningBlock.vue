<script setup lang="ts">
import { Brain } from '@lucide/vue';

defineProps<{ reasoning: string; streaming?: boolean }>();
</script>

<template>
    <details
        class="mb-2 rounded-md border border-border bg-bg-elev px-2.5 py-1.5 text-[12px] text-muted-foreground"
        data-reasoning-block
    >
        <summary class="flex cursor-pointer items-center gap-1.5 select-none">
            <Brain class="size-3.5" />
            <span :class="streaming ? 'mm-pulse' : ''">{{
                streaming ? 'Thinking…' : 'Thought process'
            }}</span>
        </summary>
        <p class="mt-1.5 whitespace-pre-wrap">{{ reasoning }}</p>
    </details>
</template>
