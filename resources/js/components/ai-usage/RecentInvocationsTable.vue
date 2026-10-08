<script setup lang="ts">
import { computed } from 'vue';
import { InitialsAvatar, Pill } from '@/components/mm';
import { formatCost, formatNumber, formatTimestamp } from './format';
import type { KindOption, RecentRow, TierFilter } from './types';

const props = defineProps<{
    rows: RecentRow[];
    kinds: KindOption[];
    tier: TierFilter | null;
    scenarioActive: boolean;
    scenarioRows?: RecentRow[];
}>();

const emit = defineEmits<{ open: [row: RecentRow] }>();

const projected = computed(() =>
    Object.fromEntries(
        (props.scenarioRows ?? []).map((row) => [row.id, row.cost] as const),
    ),
);

function kindLabel(value: string): string {
    return props.kinds.find((kind) => kind.value === value)?.label ?? value;
}
</script>

<template>
    <div
        class="overflow-hidden rounded-xl border border-border bg-card"
        data-usage-ledger
    >
        <div
            class="border-b border-border px-4 py-3 text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
        >
            Recent invocations
            <p
                v-if="tier"
                class="mt-1 text-[11.5px] font-normal tracking-normal text-muted-foreground normal-case"
            >
                Tier filter applies to this list; totals include every run.
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            v-for="h in [
                                'When',
                                'User',
                                'Model',
                                'Tokens',
                                'Tools',
                                'Cost',
                                ...(scenarioActive ? ['Projected'] : []),
                                'Status',
                            ]"
                            :key="h"
                            class="border-b border-border px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            {{ h }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="cursor-pointer border-b border-border last:border-b-0 hover:bg-bg-hover"
                        :data-usage-row="row.id"
                        @click="emit('open', row)"
                    >
                        <td
                            class="font-mono-tabular px-3 py-2 text-[11.5px] whitespace-nowrap text-fg-subtle"
                        >
                            {{ formatTimestamp(row.created_at) }}
                        </td>
                        <td class="px-3 py-2">
                            <span class="flex items-center gap-2">
                                <InitialsAvatar
                                    :name="row.user_name ?? 'system'"
                                    :size="20"
                                />
                                <span>{{ row.user_name ?? 'System' }}</span>
                            </span>
                        </td>
                        <td class="font-mono-tabular px-3 py-2 text-[12px]">
                            <span class="flex items-center gap-1.5">
                                <span>{{ row.model ?? '—' }}</span>
                                <Pill
                                    v-if="row.kind !== 'text'"
                                    data-usage-kind
                                >
                                    {{ kindLabel(row.kind) }}
                                </Pill>
                                <Pill
                                    v-if="(row.tier_position ?? 1) > 1"
                                    data-usage-tier
                                >
                                    Tier {{ row.tier_position }}
                                </Pill>
                            </span>
                        </td>
                        <td class="font-mono-tabular px-3 py-2 text-right">
                            {{ formatNumber(row.total_tokens) }}
                        </td>
                        <td class="font-mono-tabular px-3 py-2 text-right">
                            {{ row.tool_calls_count }}
                        </td>
                        <td class="font-mono-tabular px-3 py-2 text-right">
                            {{ formatCost(row.cost) }}
                        </td>
                        <td
                            v-if="scenarioActive"
                            class="font-mono-tabular px-3 py-2 text-right text-accent"
                        >
                            {{ formatCost(projected[row.id] ?? '0') }}
                        </td>
                        <td class="px-3 py-2">
                            <span
                                v-if="row.status === 'failed'"
                                class="flex max-w-[260px] flex-col gap-0.5"
                            >
                                <Pill variant="danger" dot class="self-start"
                                    >failed</Pill
                                >
                                <span
                                    v-if="row.error_message"
                                    class="truncate text-[11px] text-destructive"
                                    :title="row.error_message"
                                    data-usage-error
                                >
                                    {{ row.error_message }}
                                </span>
                            </span>
                            <Pill
                                v-else
                                :variant="
                                    row.status === 'success' ? 'ok' : 'danger'
                                "
                                dot
                            >
                                {{ row.status }}
                            </Pill>
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td
                            :colspan="scenarioActive ? 8 : 7"
                            class="px-3 py-6 text-center text-sm text-fg-subtle"
                        >
                            No invocations in this window.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
