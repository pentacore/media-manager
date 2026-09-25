<script setup lang="ts">
import { Check, Loader2, X } from '@lucide/vue';
import { computed } from 'vue';
import type { ChatToolCall } from '@/composables/useAiChat';

const props = defineProps<{ call: ChatToolCall }>();

const label = computed(() =>
    props.call.name
        .replace(/(Tool|Agent)$/, '')
        .replace(/([a-z])([A-Z])/g, '$1 $2'),
);
</script>

<template>
    <div
        class="inline-flex flex-col gap-0.5 rounded-md border border-border bg-bg-elev px-2 py-1 text-[11.5px] text-muted-foreground"
        :data-tool-chip="call.status"
    >
        <span class="inline-flex items-center gap-1.5">
            <Loader2
                v-if="call.status === 'running'"
                class="size-3 animate-spin"
            />
            <Check
                v-else-if="call.status === 'done'"
                class="size-3 text-success"
            />
            <X v-else class="size-3 text-destructive" />
            {{ label }}
        </span>
        <span
            v-for="(step, i) in call.activity"
            :key="i"
            class="max-w-80 truncate pl-4 text-[11px] text-fg-subtle"
            data-tool-activity
        >
            ↳ {{ step }}
        </span>
    </div>
</template>
