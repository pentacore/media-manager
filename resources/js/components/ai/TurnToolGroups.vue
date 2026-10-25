<script setup lang="ts">
import { computed } from 'vue';
import { SOURCE_LABELS, sourceClasses } from './toolGroupSources';
import type { TurnToolGroups } from './types';

const props = defineProps<{
    /** The groups the reply had; null shows nothing. */
    groups: TurnToolGroups | undefined;
}>();

const list = computed(() => (Array.isArray(props.groups) ? props.groups : []));

/** A list (even an empty one) always started from the core tools. */
const hasCore = computed(() => Array.isArray(props.groups));
</script>

<template>
    <p
        v-if="groups === 'all' || hasCore"
        class="mt-1 flex flex-wrap items-center gap-1 text-[11px] text-fg-subtle"
        data-turn-tools
    >
        <span>Tools this turn:</span>
        <span v-if="groups === 'all'" data-turn-tools-all>All tools</span>
        <span
            v-if="hasCore"
            class="inline-flex items-center rounded-full border border-border bg-bg-elev px-1.5 py-px text-muted-foreground"
            data-turn-tool="core"
            >Core</span
        >
        <span
            v-for="entry in list"
            :key="entry.group"
            :class="[
                'inline-flex items-center gap-1 rounded-full border px-1.5 py-px',
                sourceClasses(entry.source),
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
