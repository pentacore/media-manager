<script setup lang="ts">
import { Inbox } from '@lucide/vue';
import { BulkCheckbox, BulkSelectAll } from '@/components/bulk';
import { Pill, StatusPill, SvcChip, TimeStamp } from '@/components/mm';
import { useCan } from '@/composables/useCan';
import { cn } from '@/lib/utils';
import {
    failureBadgeVariant,
    isDestructive,
    payloadTitle,
    svcId,
} from './actionRow';
import { failureReasonLabel } from './failure';
import type { ActionRequestRow } from './types';

defineProps<{
    rows: ActionRequestRow[];
    /** Pending rows on this page: the ones the bulk checkboxes can pick. */
    pendingIds: number[];
    /** The bulk selection. */
    bulkIds: number[];
    /** How many of `pendingIds` are in the bulk selection. */
    bulkSelectedOnPage: number;
    /** A bulk run is in flight: the selection is frozen. */
    bulkBusy: boolean;
}>();

/** The row open in the detail pane. */
const selectedId = defineModel<number | null>('selectedId', {
    required: true,
});

const emit = defineEmits<{
    toggle: [id: number, value: boolean];
    toggleAll: [value: boolean];
}>();

const { isAdmin } = useCan();
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-border bg-card">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            v-if="isAdmin"
                            class="w-8 border-b border-border bg-card px-3 py-2"
                        >
                            <BulkSelectAll
                                hide-label
                                :selected-count="bulkSelectedOnPage"
                                :page-count="pendingIds.length"
                                :disabled="bulkBusy"
                                @toggle="(value) => emit('toggleAll', value)"
                            />
                        </th>
                        <th
                            class="w-6 border-b border-border bg-card px-3 py-2"
                        />
                        <th
                            class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            Action
                        </th>
                        <th
                            class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            Service
                        </th>
                        <th
                            class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            Trigger
                        </th>
                        <th
                            class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            Status
                        </th>
                        <th
                            class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            Age
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        :data-action-row="row.id"
                        :class="
                            cn(
                                'border-b border-border transition-colors hover:bg-bg-hover',
                                selectedId === row.id && 'bg-accent/8',
                            )
                        "
                        @click="selectedId = row.id"
                    >
                        <td v-if="isAdmin" class="px-3 py-2.5 align-middle">
                            <BulkCheckbox
                                v-if="row.status === 'pending'"
                                :checked="bulkIds.includes(row.id)"
                                :disabled="bulkBusy"
                                :label="`Select ${payloadTitle(row)}`"
                                :data-bulk-select="row.id"
                                @update:checked="
                                    (value) => emit('toggle', row.id, value)
                                "
                            />
                        </td>
                        <td class="px-3 py-2.5 align-middle">
                            <span
                                :class="
                                    cn(
                                        'inline-block h-5 w-1 rounded-sm',
                                        isDestructive(row.type)
                                            ? 'bg-destructive'
                                            : 'bg-info',
                                    )
                                "
                            />
                        </td>
                        <td class="px-3 py-2.5 align-middle">
                            <div class="font-medium">
                                {{ payloadTitle(row) }}
                            </div>
                            <div
                                class="font-mono-tabular text-[11px] text-fg-subtle"
                            >
                                act_{{ row.id }} · {{ row.type }}
                            </div>
                        </td>
                        <td class="px-3 py-2.5 align-middle">
                            <SvcChip
                                :id="svcId(row.target_service)"
                                :label="row.target_service"
                            />
                        </td>
                        <td class="px-3 py-2.5 align-middle">
                            <span
                                class="font-mono-tabular text-[11.5px] text-muted-foreground"
                            >
                                {{ row.webhook_source ?? 'manual' }}
                            </span>
                        </td>
                        <td class="px-3 py-2.5 align-middle">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <StatusPill :status="row.status" />
                                <Pill
                                    v-if="row.status === 'failed'"
                                    :variant="failureBadgeVariant(row)"
                                    :data-action-failure-badge="row.id"
                                    :data-action-failure-tone="
                                        failureBadgeVariant(row)
                                    "
                                >
                                    {{ failureReasonLabel(row.result) }}
                                </Pill>
                            </div>
                        </td>
                        <td
                            class="font-mono-tabular px-3 py-2.5 align-middle text-[11.5px] text-fg-subtle"
                        >
                            <TimeStamp :iso="row.created_at" />
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td
                            :colspan="isAdmin ? 7 : 6"
                            class="px-3 py-12 text-center text-sm text-fg-subtle"
                        >
                            <div class="flex flex-col items-center gap-2">
                                <Inbox class="size-5" />
                                No action requests in this view.
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
