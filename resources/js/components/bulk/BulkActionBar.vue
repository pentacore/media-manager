<script setup lang="ts">
import { MoreHorizontal, X } from '@lucide/vue';
import { computed, useId } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        count: number;
        max?: number;
        /** A bulk request is in flight: Clear and the actions are disabled. */
        busy?: boolean;
    }>(),
    { max: 100, busy: false },
);

const emit = defineEmits<{
    clear: [];
}>();

/**
 * `default`: every action, inline (and the only layout when `primary` is
 * absent). `primary` + `menu`: below `sm` the bar is one 56 px row, made of
 * the count, the `primary` action and a ⋯ menu of `menu` items plus "Clear
 * selection". Pass each phone action its own hook, never the inline button's.
 */
const slots = defineSlots<{
    default(props: { disabled: boolean }): unknown;
    primary?(props: { disabled: boolean }): unknown;
    menu?(props: { disabled: boolean }): unknown;
}>();

const overLimitId = useId();
const overLimit = computed(() => props.count > props.max);
const disabled = computed(
    () => props.busy || props.count === 0 || overLimit.value,
);
/**
 * Read in render, never cached in a `computed`: slots are not reactive, so a
 * cached value would miss a parent's conditional `#primary` coming or going.
 */
function isCompact(): boolean {
    return slots.primary !== undefined;
}
</script>

<template>
    <!-- Stays mounted while a run is in flight, so the action component that
         owns the request can't be unmounted (and re-mounted idle) mid-run. -->
    <div
        v-if="count > 0 || busy"
        role="region"
        aria-label="Bulk actions"
        :class="
            cn(
                'sticky bottom-4 z-20 flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card px-4 py-2.5 shadow-lg',
                isCompact() &&
                    'max-sm:bottom-2 max-sm:h-14 max-sm:flex-nowrap max-sm:gap-2 max-sm:px-3 max-sm:py-0',
            )
        "
        data-bulk-bar
    >
        <span
            :class="
                cn(
                    'text-[13px] font-medium',
                    isCompact() &&
                        'max-sm:min-w-0 max-sm:flex-1 max-sm:truncate',
                )
            "
            aria-live="polite"
            data-bulk-count
            >{{ count }} selected</span
        >
        <span
            v-if="overLimit"
            :id="overLimitId"
            :class="
                cn(
                    'text-[12px] text-destructive',
                    isCompact() && 'max-sm:hidden',
                )
            "
            data-bulk-over-limit
        >
            Bulk actions take at most {{ max }} at a time — narrow the
            selection.
        </span>
        <div
            :class="
                cn(
                    'flex flex-wrap items-center gap-2',
                    isCompact() && 'max-sm:hidden',
                )
            "
            role="group"
            :aria-describedby="overLimit ? overLimitId : undefined"
            data-bulk-actions
        >
            <slot :disabled="disabled" />
        </div>
        <div
            v-if="isCompact()"
            class="flex shrink-0 items-center gap-2 sm:hidden"
            data-bulk-compact
        >
            <slot name="primary" :disabled="disabled" />
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="outline"
                        size="icon"
                        class="size-10"
                        aria-label="More bulk actions"
                        data-bulk-more
                    >
                        <MoreHorizontal class="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    side="top"
                    align="end"
                    class="w-60"
                    data-bulk-menu
                >
                    <DropdownMenuLabel
                        v-if="overLimit"
                        class="text-xs font-normal text-destructive"
                        data-bulk-menu-over-limit
                    >
                        Bulk actions take at most {{ max }} at a time — narrow
                        the selection.
                    </DropdownMenuLabel>
                    <slot name="menu" :disabled="disabled" />
                    <DropdownMenuSeparator v-if="slots.menu" />
                    <DropdownMenuItem
                        :disabled="busy"
                        data-bulk-menu-clear
                        @select="emit('clear')"
                    >
                        <X class="size-3.5" />Clear selection
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
        <Button
            variant="ghost"
            size="sm"
            :class="
                cn('ml-auto h-7 gap-1 text-xs', isCompact() && 'max-sm:hidden')
            "
            :disabled="busy"
            data-bulk-clear
            @click="emit('clear')"
        >
            <X class="size-3.5" />Clear
        </Button>
    </div>
</template>
