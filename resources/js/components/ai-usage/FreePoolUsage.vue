<script setup lang="ts">
import { formatNumber } from './format';
import type { FreePoolRow } from './types';

defineProps<{
    pools: FreePoolRow[];
}>();

function poolBars(pool: FreePoolRow): Array<{
    label: string;
    used: number;
    cap: number;
}> {
    if (pool.unified) {
        return [
            {
                label: 'Tokens',
                used: pool.used_total,
                cap: pool.free_total ?? 0,
            },
        ];
    }

    return [
        { label: 'Input', used: pool.used_input, cap: pool.free_input ?? 0 },
        {
            label: 'Output',
            used: pool.used_output,
            cap: pool.free_output ?? 0,
        },
    ];
}
</script>

<template>
    <div
        class="overflow-hidden rounded-xl border border-border bg-card"
        data-usage-free-pools
    >
        <div
            class="flex items-center justify-between border-b border-border px-4 py-3"
        >
            <span
                class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
            >
                Free usage pools
            </span>
            <span class="text-[11.5px] text-muted-foreground">
                Spend above subtracts what's still under quota.
            </span>
        </div>
        <div class="divide-y divide-border">
            <div
                v-for="pool in pools"
                :key="pool.id"
                class="grid items-center gap-3 px-4 py-2.5 md:grid-cols-[220px,1fr]"
                :data-usage-free-pool="pool.id"
            >
                <div>
                    <div class="text-[12.5px] font-medium">
                        {{ pool.name }}
                        <a
                            v-if="pool.documentation_url"
                            :href="pool.documentation_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="ml-1 text-[11px] text-muted-foreground underline hover:text-foreground"
                            >docs</a
                        >
                    </div>
                    <div class="text-[11px] text-muted-foreground">
                        resets {{ pool.period }} ·
                        {{
                            pool.models.length > 0
                                ? pool.models.map((m) => m.model).join(', ')
                                : 'no usage yet'
                        }}
                    </div>
                </div>
                <div
                    class="grid gap-3"
                    :class="pool.unified ? 'md:grid-cols-1' : 'md:grid-cols-2'"
                >
                    <div
                        v-for="bar in poolBars(pool)"
                        :key="bar.label"
                        class="space-y-1"
                    >
                        <div
                            class="flex items-center justify-between text-[11px]"
                        >
                            <span class="text-muted-foreground">{{
                                bar.label
                            }}</span>
                            <span class="font-mono-tabular">
                                {{ formatNumber(bar.used) }} /
                                {{
                                    bar.cap > 0
                                        ? formatNumber(bar.cap)
                                        : '— no cap'
                                }}
                            </span>
                        </div>
                        <div
                            class="h-1.5 overflow-hidden rounded-full bg-bg-elev"
                        >
                            <div
                                v-if="bar.cap > 0"
                                class="h-full transition-all"
                                :class="
                                    bar.used >= bar.cap
                                        ? 'bg-destructive'
                                        : bar.used / bar.cap > 0.8
                                          ? 'bg-warning'
                                          : 'bg-success'
                                "
                                :style="`width: ${Math.min(100, (bar.used / bar.cap) * 100)}%`"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
