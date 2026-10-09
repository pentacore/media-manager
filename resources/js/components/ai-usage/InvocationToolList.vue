<script setup lang="ts">
import { Pill } from '@/components/mm';
import { formatMs } from './format';
import type { InvocationDetail } from './types';

defineProps<{
    tools: InvocationDetail['tools'];
}>();
</script>

<template>
    <div>
        <div
            class="mb-2 text-[11px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
        >
            Tools used ({{ tools.length }})
        </div>
        <ul class="space-y-1">
            <li
                v-for="tool in tools"
                :key="tool.id"
                class="flex items-center justify-between rounded border border-border bg-card px-3 py-1.5 text-[12px]"
            >
                <span
                    class="font-mono-tabular truncate"
                    :title="tool.tool_class"
                >
                    {{ tool.tool_class.split('\\').pop() }}
                </span>
                <span class="flex items-center gap-1.5">
                    <span
                        v-if="tool.duration_ms !== null"
                        class="font-mono-tabular text-[11px] text-muted-foreground"
                    >
                        {{ formatMs(tool.duration_ms) }}
                    </span>
                    <Pill
                        v-if="tool.error_code"
                        variant="danger"
                        data-usage-tool-error
                    >
                        {{ tool.error_code }}
                    </Pill>
                    <Pill
                        :variant="tool.status === 'success' ? 'ok' : 'danger'"
                        dot
                    >
                        {{ tool.status }}
                    </Pill>
                </span>
            </li>
        </ul>
    </div>
</template>
