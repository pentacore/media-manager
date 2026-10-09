<script setup lang="ts">
import { formatNumber } from './format';
import type { RateLimitStatusRow } from './types';

defineProps<{
    rows: RateLimitStatusRow[];
    enforced: boolean;
}>();

function rateLimitLabel(limit: RateLimitStatusRow['limits'][number]): string {
    const metric = limit.metric === 'requests' ? 'req' : 'tok';

    return `${metric}/${limit.period}`;
}
</script>

<template>
    <div
        class="overflow-hidden rounded-xl border border-border bg-card"
        data-usage-rate-limits
    >
        <div
            class="flex items-center justify-between border-b border-border px-4 py-3"
        >
            <span
                class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
            >
                Rate limits
            </span>
            <span
                class="text-[11.5px] text-muted-foreground"
                data-rate-limits-mode
            >
                {{
                    enforced
                        ? 'Rolling windows ending now. Enforced: an exhausted limit blocks new requests.'
                        : 'Rolling windows ending now. Informational only.'
                }}
            </span>
        </div>
        <div class="divide-y divide-border">
            <div
                v-for="row in rows"
                :key="`${row.provider}|${row.model}`"
                class="grid items-center gap-3 px-4 py-2.5 md:grid-cols-[220px,1fr]"
            >
                <div>
                    <div class="text-[12.5px] font-medium">
                        {{ row.model }}
                    </div>
                    <div class="text-[11px] text-muted-foreground">
                        {{ row.provider }}
                    </div>
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <div
                        v-for="limit in row.limits"
                        :key="`${limit.metric}|${limit.period}`"
                        class="space-y-1"
                    >
                        <div
                            class="flex items-center justify-between text-[11px]"
                        >
                            <span class="text-muted-foreground">{{
                                rateLimitLabel(limit)
                            }}</span>
                            <span class="flex items-center gap-1.5">
                                <span
                                    v-if="
                                        enforced &&
                                        limit.used >= limit.limit_value
                                    "
                                    class="rounded-sm bg-destructive/15 px-1.5 py-0.5 text-[10px] font-semibold tracking-[0.04em] text-destructive uppercase"
                                    data-rate-limit-blocked
                                >
                                    Blocked
                                </span>
                                <span class="font-mono-tabular">
                                    {{ formatNumber(limit.used) }} /
                                    {{ formatNumber(limit.limit_value) }}
                                </span>
                            </span>
                        </div>
                        <div
                            class="h-1.5 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full"
                                :class="
                                    limit.used >= limit.limit_value
                                        ? 'bg-destructive'
                                        : 'bg-primary'
                                "
                                :style="{
                                    width: `${Math.min(100, (limit.used / limit.limit_value) * 100)}%`,
                                }"
                            ></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
