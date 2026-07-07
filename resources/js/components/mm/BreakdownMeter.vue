<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    title: string;
    rows: { key: string; count: number }[];
    emptyText: string;
}>();

/** Bar widths are relative to the largest row so the top row fills the track. */
const meterRows = computed(() => {
    const max =
        props.rows.reduce((acc, row) => Math.max(acc, row.count), 0) || 1;

    return props.rows.map((row) => ({
        ...row,
        pct: Math.round((row.count / max) * 100),
    }));
});
</script>

<template>
    <div class="rounded-xl border border-border bg-card p-5">
        <h2 class="mb-4 text-sm font-semibold">{{ title }}</h2>
        <div v-if="meterRows.length" class="space-y-3">
            <div v-for="row in meterRows" :key="row.key">
                <div class="mb-1 flex items-center justify-between text-xs">
                    <span class="text-muted-foreground">{{ row.key }}</span>
                    <span class="tabular-nums">{{ row.count }}</span>
                </div>
                <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full rounded-full bg-accent transition-all"
                        :style="`width: ${row.pct}%`"
                    />
                </div>
            </div>
        </div>
        <p v-else class="text-sm text-muted-foreground">{{ emptyText }}</p>
    </div>
</template>
