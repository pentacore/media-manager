<script setup lang="ts">
import { MoreVertical } from '@lucide/vue';
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

/**
 * The grab queue's per-item admin menu, shared by the desktop table row and
 * the phone card: the same items and handlers in both. Only the trigger's
 * size and hook differ.
 */
const props = defineProps<{
    /** The queue item's title, for the trigger's accessible name. */
    title: string | null;
    /** Manual import needs the download client's id. */
    canManualImport: boolean;
    /** A remove or blocklist for this item is in flight. */
    busy: boolean;
    /** `row`: the table's compact trigger; `card`: the phone card's. */
    variant: 'row' | 'card';
    /** The card's key (`sonarr-41`), for its trigger hook. */
    cardKey?: string;
}>();

const emit = defineEmits<{
    grab: [];
    manualImport: [];
    remove: [verb: 'remove' | 'block'];
}>();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="sm"
                :class="cn('size-7 p-0', props.variant === 'card' && 'size-9')"
                :disabled="busy"
                :aria-label="`Manage ${title ?? 'queue item'}`"
                :data-queue-row-menu="variant === 'row' ? '' : undefined"
                :data-queue-card-menu="variant === 'card' ? cardKey : undefined"
            >
                <MoreVertical class="size-3.5" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-52">
            <DropdownMenuLabel>Manage queue item</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem data-queue-grab @select="emit('grab')">
                Force grab now
            </DropdownMenuItem>
            <DropdownMenuItem
                data-queue-manual-import
                :disabled="!canManualImport"
                @select="emit('manualImport')"
            >
                Manual import…
            </DropdownMenuItem>
            <DropdownMenuItem
                data-queue-remove="remove"
                @select="emit('remove', 'remove')"
            >
                Remove from queue
            </DropdownMenuItem>
            <DropdownMenuItem
                class="text-destructive focus:text-destructive"
                data-queue-remove="block"
                @select="emit('remove', 'block')"
            >
                Blocklist & retry
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
