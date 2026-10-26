<script setup lang="ts">
import { Inbox } from '@lucide/vue';
import { BulkCheckbox } from '@/components/bulk';
import {
    PhoneCardList,
    Pill,
    StatusPill,
    SvcChip,
    TimeStamp,
} from '@/components/mm';
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

/**
 * The Action Queue below `sm`: one card per row, with the same facts as the
 * table's row (type, target, status, failure reason, age). The whole card
 * body is one button that opens the action. The bulk checkbox sits beside it,
 * never inside it.
 */
defineProps<{
    rows: ActionRequestRow[];
    /** The bulk selection. */
    bulkIds: number[];
    /** A bulk run is in flight: the selection is frozen. */
    bulkBusy: boolean;
}>();

const emit = defineEmits<{
    open: [id: number];
    toggle: [id: number, value: boolean];
}>();

const { isAdmin } = useCan();
</script>

<template>
    <PhoneCardList
        :items="rows"
        :item-key="(row) => row.id"
        label="Action requests"
        data-action-cards
    >
        <template #card="{ item: row }">
            <div class="flex items-start gap-3" :data-action-card="row.id">
                <BulkCheckbox
                    v-if="isAdmin && row.status === 'pending'"
                    class="pt-1"
                    :checked="bulkIds.includes(row.id)"
                    :disabled="bulkBusy"
                    :label="`Select ${payloadTitle(row)}`"
                    :data-action-card-select="row.id"
                    @update:checked="(value) => emit('toggle', row.id, value)"
                />
                <button
                    type="button"
                    class="flex min-w-0 flex-1 scroll-mb-[calc(4.5rem+env(safe-area-inset-bottom))] flex-col gap-1.5 rounded-md text-left outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                    :data-action-card-open="row.id"
                    @click="emit('open', row.id)"
                >
                    <span class="flex items-start gap-2">
                        <span
                            :class="
                                cn(
                                    'mt-0.5 inline-block h-4 w-1 shrink-0 rounded-sm',
                                    isDestructive(row.type)
                                        ? 'bg-destructive'
                                        : 'bg-info',
                                )
                            "
                        />
                        <span
                            class="min-w-0 flex-1 text-[14px] leading-snug font-medium break-words"
                            data-action-card-title
                            >{{ payloadTitle(row) }}</span
                        >
                    </span>
                    <span
                        class="font-mono-tabular text-[11px] break-all text-fg-subtle"
                        >act_{{ row.id }} · {{ row.type }}</span
                    >
                    <span class="flex flex-wrap items-center gap-1.5">
                        <SvcChip
                            :id="svcId(row.target_service)"
                            :label="row.target_service"
                        />
                        <StatusPill :status="row.status" />
                        <Pill
                            v-if="row.status === 'failed'"
                            :variant="failureBadgeVariant(row)"
                            :data-action-card-failure="row.id"
                        >
                            {{ failureReasonLabel(row.result) }}
                        </Pill>
                        <TimeStamp
                            :iso="row.created_at"
                            class="font-mono-tabular ml-auto text-[11.5px] text-fg-subtle"
                        />
                    </span>
                </button>
            </div>
        </template>
        <template #empty>
            <div
                class="flex flex-col items-center gap-2 rounded-xl border border-border bg-card px-3 py-12 text-center text-sm text-fg-subtle"
            >
                <Inbox class="size-5" />
                No action requests in this view.
            </div>
        </template>
    </PhoneCardList>
</template>
