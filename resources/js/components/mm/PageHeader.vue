<script setup lang="ts">
import { MoreHorizontal } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

/**
 * A page's hero header. From `sm` up it is the side-by-side row pages used to
 * hand-roll (title block left, actions right). Below `sm` it stacks: the title
 * block, then the filters (wrapping), then the primary action and a ⋯ menu
 * holding the secondary actions. Secondary actions are passed twice: inline
 * buttons in #secondaryActions (desktop) and DropdownMenuItems in #menu
 * (phone), because a menu needs real menu items for its keyboard model.
 */
const props = withDefaults(
    defineProps<{
        align?: 'end' | 'start' | 'center';
        primaryFirst?: boolean;
        actionsClass?: HTMLAttributes['class'];
        menuLabel?: string;
        class?: HTMLAttributes['class'];
    }>(),
    {
        align: 'end',
        primaryFirst: false,
        menuLabel: 'More actions',
    },
);

defineSlots<{
    eyebrow?: () => unknown;
    title: () => unknown;
    description?: () => unknown;
    filters?: () => unknown;
    secondaryActions?: () => unknown;
    primaryAction?: () => unknown;
    menu?: () => unknown;
}>();

const alignClasses = {
    end: 'sm:items-end',
    start: 'sm:items-start',
    center: 'sm:items-center',
} as const;
</script>

<template>
    <div
        data-page-header
        :class="
            cn(
                'flex flex-col gap-3 sm:flex-row sm:justify-between',
                alignClasses[props.align],
                props.class,
            )
        "
    >
        <div class="min-w-0">
            <slot name="eyebrow" />
            <slot name="title" />
            <slot name="description" />
        </div>
        <div
            v-if="
                $slots.filters ||
                $slots.secondaryActions ||
                $slots.primaryAction ||
                $slots.menu
            "
            data-page-header-actions
            :class="
                cn(
                    'flex flex-wrap items-center gap-2 sm:flex-nowrap',
                    props.actionsClass,
                )
            "
        >
            <div
                v-if="$slots.filters"
                data-page-header-filters
                class="flex w-full min-w-0 flex-wrap items-center gap-2 sm:contents"
            >
                <slot name="filters" />
            </div>
            <slot v-if="props.primaryFirst" name="primaryAction" />
            <div
                v-if="$slots.secondaryActions"
                data-page-header-secondary
                class="hidden sm:contents"
            >
                <slot name="secondaryActions" />
            </div>
            <slot v-if="!props.primaryFirst" name="primaryAction" />
            <DropdownMenu v-if="$slots.menu">
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="outline"
                        size="icon-sm"
                        class="size-7 sm:hidden"
                        :aria-label="props.menuLabel"
                        data-page-header-more
                    >
                        <MoreHorizontal class="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    class="w-56"
                    data-page-header-menu
                >
                    <slot name="menu" />
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </div>
</template>
