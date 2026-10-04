<script setup lang="ts">
import { computed } from 'vue';
import type { PreviewSegment } from './types';

const props = defineProps<{
    segments: PreviewSegment[];
    errors: Record<string, string[]>;
    failed: boolean;
}>();

const messages = computed(() => [
    ...new Set(Object.values(props.errors).flat()),
]);
</script>

<template>
    <div class="grid gap-2">
        <div
            class="min-h-12 rounded-lg border border-border bg-bg-elev p-3 text-[13.5px] leading-relaxed whitespace-pre-wrap"
            data-template-preview
        >
            <span
                v-if="failed"
                class="text-fg-subtle"
                data-template-preview-unavailable
                >Preview unavailable.</span
            >
            <template v-else>
                <span v-if="segments.length === 0" class="text-fg-subtle"
                    >Nothing to preview yet.</span
                >
                <template v-for="(segment, index) in segments" :key="index">
                    <span
                        v-if="segment.kind === 'placeholder'"
                        class="rounded border border-accent/30 bg-accent/12 px-1 text-accent"
                        data-preview-placeholder
                        >{{ segment.value }}</span
                    >
                    <span v-else>{{ segment.value }}</span>
                </template>
            </template>
        </div>
        <ul
            v-if="!failed && messages.length > 0"
            class="grid gap-1 text-[12.5px] text-destructive"
            data-template-preview-errors
        >
            <li v-for="message in messages" :key="message">{{ message }}</li>
        </ul>
    </div>
</template>
