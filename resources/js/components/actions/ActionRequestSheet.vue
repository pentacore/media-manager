<script setup lang="ts">
import { nextTick, watch } from 'vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import ActionRequestDetail from './ActionRequestDetail.vue';
import { payloadTitle } from './actionRow';
import type { ActionRequestRow } from './types';

/**
 * The Action Queue's phone detail: `ActionRequestDetail` in its `sheet`
 * layout, full screen. Closing returns to the list where it was: the list's
 * scroll position is restored and the action's card gets focus.
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

/** The window's scroll position when the sheet opened. */
let listScrollTop = 0;

/** The action the sheet showed last, so focus returns to its card. */
let shownId: number | null = null;

watch(
    open,
    (isOpen) => {
        if (isOpen) {
            listScrollTop = window.scrollY;
            shownId = props.selected?.id ?? null;
        }
    },
    { flush: 'sync' },
);

watch(
    () => props.selected?.id ?? null,
    (id) => {
        if (open.value && id !== null) {
            shownId = id;
        }
    },
);

/**
 * Reka would focus whatever had focus before the sheet opened, and could
 * scroll the page to it. Return to the card instead, where the list was.
 */
function returnToList(event: Event): void {
    event.preventDefault();

    const card =
        shownId === null
            ? null
            : document.querySelector<HTMLElement>(
                  `[data-action-card-open="${shownId}"]`,
              );

    card?.focus({ preventScroll: true });
    void nextTick(() =>
        window.scrollTo({ top: listScrollTop, behavior: 'instant' }),
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
                selected ? payloadTitle(selected) : 'Action request'
            }}</SheetTitle>
            <SheetDescription class="sr-only">
                Why this action was proposed, its target and its outcome.
                Approve or decline it at the bottom.
            </SheetDescription>
            <ActionRequestDetail
                v-if="selected"
                layout="sheet"
                :selected="selected"
                @approve="(id) => emit('approve', id)"
                @reject="(id) => emit('reject', id)"
                @retry="(row) => emit('retry', row)"
            />
        </SheetContent>
    </Sheet>
</template>
