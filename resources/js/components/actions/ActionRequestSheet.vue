<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { focusPageHeading } from '@/lib/bulk';
import ActionRequestDetail from './ActionRequestDetail.vue';
import { payloadTitle } from './actionRow';
import type { ActionRequestRow } from './types';

/**
 * The Action Queue's phone detail: `ActionRequestDetail` in its `sheet`
 * layout, full screen. Closing returns to the list: the action's card is
 * brought back into view and gets focus.
 */
const props = defineProps<{
    selected: ActionRequestRow | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    approve: [id: number];
    reject: [id: number];
    retry: [row: ActionRequestRow];
}>();

/**
 * The row the sheet shows. It follows `selected` only while the sheet is
 * open, so a sheet sliding out after its row left the list keeps showing that
 * row (inert) instead of the page's next selection.
 */
const shownRow = ref<ActionRequestRow | null>(props.selected);

watch(
    [open, () => props.selected],
    ([isOpen, row]) => {
        if (isOpen) {
            shownRow.value = row;
        }
    },
    { flush: 'sync', immediate: true },
);

/**
 * Reka would focus whatever had focus before the sheet opened, and could
 * scroll the page to it. Return to the card instead, bringing it back into
 * view even if rows were added above it meanwhile. When the card has left the
 * list, focus goes to the page heading.
 */
function returnToList(event: Event): void {
    event.preventDefault();

    const card =
        shownRow.value === null
            ? null
            : document.querySelector<HTMLElement>(
                  `[data-action-card-open="${shownRow.value.id}"]`,
              );

    if (card === null) {
        focusPageHeading({ preventScroll: true });

        return;
    }

    card.focus({ preventScroll: true });
    void nextTick(() =>
        card.scrollIntoView({ block: 'nearest', behavior: 'instant' }),
    );
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            side="right"
            class="w-full gap-0 overflow-y-auto p-0 sm:max-w-none"
            data-action-sheet
            @close-auto-focus="returnToList"
        >
            <SheetTitle class="sr-only">{{
                shownRow ? payloadTitle(shownRow) : 'Action request'
            }}</SheetTitle>
            <SheetDescription class="sr-only">
                Why this action was proposed, its target and its outcome.
                Approve or decline it at the bottom.
            </SheetDescription>
            <ActionRequestDetail
                v-if="shownRow"
                layout="sheet"
                :selected="shownRow"
                :inert="!open"
                @approve="(id) => emit('approve', id)"
                @reject="(id) => emit('reject', id)"
                @retry="(row) => emit('retry', row)"
            />
        </SheetContent>
    </Sheet>
</template>
