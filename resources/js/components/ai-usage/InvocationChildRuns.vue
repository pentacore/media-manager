<script setup lang="ts">
import { Pill } from '@/components/mm';
import { formatNumber, shortClass } from './format';
import type { ChildRun } from './types';

defineProps<{
    runs: ChildRun[];
}>();
</script>

<template>
    <div data-usage-children>
        <div
            class="mb-2 text-[11px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
        >
            Sub-agent runs ({{ runs.length }})
        </div>
        <ul class="space-y-1">
            <li
                v-for="child in runs"
                :key="child.id"
                class="flex items-center justify-between rounded border border-border bg-card px-3 py-1.5 text-[12px]"
            >
                <span
                    class="font-mono-tabular truncate"
                    :title="child.agent_class ?? ''"
                >
                    {{ shortClass(child.agent_class) }}
                    <span class="text-muted-foreground">
                        · {{ child.model ?? '—' }}
                    </span>
                </span>
                <span class="flex items-center gap-1.5">
                    <span
                        class="font-mono-tabular text-[11px] text-muted-foreground"
                    >
                        {{ formatNumber(child.total_tokens) }}
                        tok
                    </span>
                    <Pill
                        :variant="child.status === 'success' ? 'ok' : 'danger'"
                        dot
                    >
                        {{ child.status }}
                    </Pill>
                </span>
            </li>
        </ul>
    </div>
</template>
