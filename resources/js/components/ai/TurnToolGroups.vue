<script setup lang="ts">
import { computed } from 'vue';
import type { ToolGroupSource, TurnToolGroups } from './types';

const props = defineProps<{
    /** The groups the reply had; null shows nothing. */
    groups: TurnToolGroups | undefined;
}>();

const SOURCE_LABELS: Record<ToolGroupSource, string> = {
    pinned: 'Pinned',
    assistant: 'Assistant',
    carried_over: 'Carried over',
    picked: 'Picked',
};

const list = computed(() => (Array.isArray(props.groups) ? props.groups : []));
</script>

<template>
    <p
        v-if="groups === 'all' || list.length > 0"
        class="mt-1 flex flex-wrap items-center gap-1 text-[11px] text-fg-subtle"
        data-turn-tools
    >
        <span>Tools this turn:</span>
        <span v-if="groups === 'all'" data-turn-tools-all>All tools</span>
        <span
            v-for="entry in list"
            :key="entry.group"
            :class="[
                'inline-flex items-center gap-1 rounded-full border px-1.5 py-px',
                entry.source === 'pinned'
                    ? 'border-accent/28 bg-accent/10 text-accent'
                    : entry.source === 'assistant'
                      ? 'border-success/30 bg-success/10 text-success'
                      : 'border-border bg-bg-elev text-muted-foreground',
            ]"
            :data-turn-tool="entry.group"
            :data-turn-tool-source="entry.source"
        >
            {{ entry.label }}
            <span class="text-[9.5px] tracking-wide uppercase opacity-70">{{
                SOURCE_LABELS[entry.source]
            }}</span>
        </span>
    </p>
</template>
