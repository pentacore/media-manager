<script setup lang="ts" generic="T">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/**
 * A list of stacked cards that stands in for a table below `sm`. The page
 * keeps its table and gives the table's frame `max-sm:hidden`. This list is
 * `sm:hidden` and comes after the table in the DOM, so desktop text clicks
 * keep finding the table first. Hooks inside a card get their own
 * `data-<x>-card-*` names: a strict selector also counts hidden elements.
 * The root is `min-w-0`: as a grid or flex item it must not let a long
 * unbroken title (`break-words` keeps it in min-content) widen its track.
 */
const props = defineProps<{
    items: T[];
    itemKey: (item: T) => string | number;
    /** The list's accessible name, e.g. "Action requests". */
    label: string;
    class?: HTMLAttributes['class'];
}>();

defineSlots<{
    card(props: { item: T; index: number }): unknown;
    empty?(): unknown;
}>();
</script>

<template>
    <div data-phone-card-list :class="cn('min-w-0 sm:hidden', props.class)">
        <ul
            v-if="items.length > 0"
            :aria-label="label"
            class="flex flex-col gap-2"
        >
            <li
                v-for="(item, index) in items"
                :key="itemKey(item)"
                class="rounded-xl border border-border bg-card p-3"
                data-phone-card
            >
                <slot name="card" :item="item" :index="index" />
            </li>
        </ul>
        <slot v-else name="empty" />
    </div>
</template>
