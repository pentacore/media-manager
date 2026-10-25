<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { useCan } from '@/composables/useCan';
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

// The default `pre` flush runs this once per update, after both `open` and
// `selected` have their new values, so it never depends on which attribute
// the page's template sets first (a `sync` watcher would see `selected`
// change while `open` is still true, and freeze on the next action).
watch(
    [open, () => props.selected],
    ([isOpen, row]) => {
        if (isOpen) {
            shownRow.value = row;
        }
    },
    { immediate: true },
);

const { isAdmin } = useCan();

/** What the bottom bar offers for the shown row, for the sheet's description. */
const reviewHint = computed<string>(() => {
    if (shownRow.value?.status === 'pending') {
        return 'Approve or decline it at the bottom.';
    }

    if (shownRow.value?.status === 'failed' && isAdmin.value) {
        return 'Retry it at the bottom.';
    }

    return '';
});

/**
 * Reka would focus the first tabbable control, which for a pending action is
 * the pinned "Approve & execute": a held Enter would approve it, and a screen
 * reader would skip the title, the why and the warning. Start on the title.
 */
function focusTitle(event: Event): void {
    event.preventDefault();

    const sheet = document.querySelector<HTMLElement>('[data-action-sheet]');
    const title =
        sheet?.querySelector<HTMLElement>('[data-action-title]') ?? sheet;

    if (!title) {
        return;
    }

    if (!title.hasAttribute('tabindex')) {
        title.setAttribute('tabindex', '-1');
    }

    title.focus({ preventScroll: true });
}

/**
 * Reka would focus whatever had focus before the sheet opened, and could
 * scroll the page to it. Return to the card instead, bringing it back into
 * view even if rows were added above it meanwhile. When the card has left the list,
 * focus goes to the page heading.
 */
function returnToList(event: Event): void {
    event.preventDefault();

    const card =
        shownRow.value === null
            ? null
            : document.querySelector<HTMLElement>(
                  `[data-action-card-open="${shownRow.value.id}"]`,
              );

    // A card with no box (the viewport crossed `sm` while the sheet was open,
    // hiding the card list) cannot take focus either.
    if (card === null || card.getClientRects().length === 0) {
        focusPageHeading();

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
            @open-auto-focus="focusTitle"
            @close-auto-focus="returnToList"
        >
            <SheetTitle class="sr-only">{{
                shownRow ? payloadTitle(shownRow) : 'Action request'
            }}</SheetTitle>
            <SheetDescription class="sr-only">
                Why this action was proposed, its target and its outcome.
                {{ reviewHint }}
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
