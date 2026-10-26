<script setup lang="ts">
import { LOW_SAMPLE, percent } from '@/components/ai/secondOpinionAgreement';
import type { AgreementBreakdownRow } from '@/components/ai/secondOpinionAgreement';
import { Pill } from '@/components/mm';
import { formatCount } from '@/lib/format';

withDefaults(
    defineProps<{
        title: string;
        heading: string;
        dimension: 'event' | 'action_type' | 'answered_by';
        rows: AgreementBreakdownRow[];
        overriddenEvents?: string[];
    }>(),
    { overriddenEvents: () => [] },
);

const headerClass =
    'border-b border-border px-3 py-2 text-[11.5px] font-medium tracking-[0.05em] whitespace-nowrap text-muted-foreground uppercase';
</script>

<template>
    <div
        class="flex min-w-0 flex-col gap-2"
        :data-agreement-breakdown="dimension"
    >
        <span class="text-[13px] font-medium">{{ title }}</span>
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th :class="[headerClass, 'text-left']">
                            {{ heading }}
                        </th>
                        <th
                            v-for="h in [
                                'Verdicts',
                                'Reviewed',
                                'Agreement',
                                'False alarms',
                                'Misses',
                            ]"
                            :key="h"
                            :class="[headerClass, 'text-right']"
                        >
                            {{ h }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.key"
                        class="border-b border-border last:border-b-0"
                        :data-breakdown-row="row.key"
                    >
                        <td class="px-3 py-2">
                            <span class="flex items-center gap-2">
                                <span class="break-all">{{ row.label }}</span>
                                <Pill
                                    v-if="overriddenEvents.includes(row.key)"
                                    variant="info"
                                    data-breakdown-override
                                    >per-event rule</Pill
                                >
                            </span>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ formatCount(row.verdicts) }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ formatCount(row.reviewed) }}
                        </td>
                        <td
                            class="px-3 py-2 text-right tabular-nums"
                            :class="
                                row.reviewed < LOW_SAMPLE
                                    ? 'text-muted-foreground'
                                    : ''
                            "
                            :data-low-sample="row.reviewed < LOW_SAMPLE"
                            data-breakdown-agreement
                        >
                            {{ percent(row.agreement) }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ formatCount(row.false_alarms) }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ formatCount(row.misses) }}
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td
                            colspan="6"
                            class="px-3 py-4 text-center text-fg-subtle"
                        >
                            Nothing in this window.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
