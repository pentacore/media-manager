<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ACTION_TABS } from './actionRow';

const props = defineProps<{
    /** The page's `filters.status`: '' for every status. */
    status: string;
    statusCounts: Record<string, number>;
    /** Requests in the current view, shown on the All tab. */
    total: number;
}>();

const emit = defineEmits<{
    select: [id: string];
}>();

function currentFilter(): string {
    return props.status === '' ? 'all' : props.status;
}

function statusCount(id: string): number {
    if (id === 'all') {
        return Object.values(props.statusCounts).reduce((sum, n) => sum + n, 0);
    }

    return props.statusCounts[id] ?? 0;
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5 touch:gap-y-3">
        <button
            v-for="tab in ACTION_TABS"
            :key="tab.id"
            type="button"
            :data-test="`tab-${tab.id}`"
            :class="
                cn(
                    'touch-target inline-flex h-7 items-center gap-1.5 rounded-md px-2.5 text-xs font-medium transition-colors',
                    currentFilter() === tab.id
                        ? 'bg-accent text-accent-foreground'
                        : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                )
            "
            @click="emit('select', tab.id)"
        >
            {{ tab.label }}
            <span
                v-if="tab.id === 'all' || statusCount(tab.id) > 0"
                class="font-mono-tabular text-[11px] opacity-70"
            >
                {{ tab.id === 'all' ? total : statusCount(tab.id) }}
            </span>
        </button>
    </div>
</template>
