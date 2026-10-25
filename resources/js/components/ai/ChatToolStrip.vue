<script setup lang="ts">
import { Eye, EyeOff, Pin, Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type {
    ToolGroupOption,
    ToolGroupSource,
    ToolGroupValue,
    TurnToolGroup,
} from './types';

const props = defineProps<{
    routingEnabled: boolean;
    options: ToolGroupOption[];
    pinned: ToolGroupValue[];
    carriedOver: TurnToolGroup[];
    showTurnLines: boolean;
    /** A turn or load is in flight, so pins cannot change. */
    disabled: boolean;
}>();

const emit = defineEmits<{
    (e: 'pin', value: ToolGroupValue): void;
    (e: 'unpin', value: ToolGroupValue): void;
    (e: 'toggle-lines'): void;
}>();

const SOURCE_LABELS: Record<ToolGroupSource, string> = {
    pinned: 'Pinned',
    assistant: 'Assistant',
    carried_over: 'Carried over',
    picked: 'Picked',
};

/** The pinned groups, flagged when their integration is no longer there. */
const pinnedChips = computed(() =>
    props.pinned.map((value) => {
        const option = props.options.find((o) => o.value === value);

        return {
            value,
            label: option?.label ?? value,
            unavailable: option === undefined || !option.available,
        };
    }),
);

/** Groups the next turn would carry over that are not already pinned. */
const carriedChips = computed(() =>
    props.carriedOver.filter((entry) => !props.pinned.includes(entry.group)),
);

/** Groups the user can still pin: available and not pinned yet. */
const pinnable = computed(() =>
    props.options.filter(
        (option) => option.available && !props.pinned.includes(option.value),
    ),
);
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-1.5 px-4 pt-2 text-[11px]"
        data-chat-tool-strip
    >
        <span
            v-if="!routingEnabled"
            class="inline-flex items-center rounded-full border border-border bg-bg-elev px-2 py-0.5 text-muted-foreground"
            data-tool-all
            >All tools</span
        >
        <template v-else>
            <span
                class="inline-flex items-center rounded-full border border-border bg-bg-elev px-2 py-0.5 text-muted-foreground"
                data-tool-core
                >Core</span
            >
            <span
                v-for="entry in carriedChips"
                :key="`carried-${entry.group}`"
                :class="[
                    'inline-flex items-center gap-1 rounded-full border px-2 py-0.5',
                    entry.source === 'assistant'
                        ? 'border-success/30 bg-success/10 text-success'
                        : 'border-border bg-bg-elev text-muted-foreground',
                ]"
                :data-tool-chip="entry.group"
                :data-tool-source="entry.source"
            >
                {{ entry.label }}
                <span class="text-[9.5px] tracking-wide uppercase opacity-70">{{
                    SOURCE_LABELS[entry.source]
                }}</span>
            </span>
            <span
                v-for="chip in pinnedChips"
                :key="`pinned-${chip.value}`"
                :class="[
                    'inline-flex items-center gap-1 rounded-full border border-accent/28 bg-accent/10 py-0.5 pr-1 pl-2 text-accent',
                    chip.unavailable && 'opacity-50',
                ]"
                :title="
                    chip.unavailable
                        ? 'Not available — its integration isn\'t configured'
                        : undefined
                "
                :data-tool-chip="chip.value"
                data-tool-source="pinned"
                :data-tool-unavailable="chip.unavailable ? '' : undefined"
            >
                <Pin class="size-3" />
                {{ chip.label }}
                <button
                    type="button"
                    class="touch-target inline-flex size-4 items-center justify-center rounded-full hover:bg-accent/20 disabled:opacity-50"
                    :disabled="disabled"
                    :aria-label="`Unpin ${chip.label}`"
                    :data-tool-unpin="chip.value"
                    @click="emit('unpin', chip.value)"
                >
                    <X class="size-3" />
                </button>
            </span>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-full border border-dashed border-border px-2 py-0.5 text-muted-foreground hover:text-foreground disabled:opacity-50"
                        :disabled="disabled"
                        data-tool-add
                    >
                        <Plus class="size-3" />
                        Add tools
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" class="w-56">
                    <DropdownMenuItem
                        v-for="option in pinnable"
                        :key="option.value"
                        :data-tool-pin="option.value"
                        @select="emit('pin', option.value)"
                    >
                        <Pin class="size-3.5" />
                        {{ option.label }}
                    </DropdownMenuItem>
                    <p
                        v-if="pinnable.length === 0"
                        class="px-2 py-1.5 text-[12px] text-muted-foreground"
                    >
                        Every available group is pinned.
                    </p>
                </DropdownMenuContent>
            </DropdownMenu>
        </template>
        <button
            type="button"
            class="ml-auto inline-flex items-center gap-1 rounded-md border border-border px-1.5 py-0.5 text-muted-foreground hover:text-foreground"
            :title="
                showTurnLines
                    ? 'Hide the tools under each reply'
                    : 'Show the tools under each reply'
            "
            :aria-pressed="showTurnLines"
            data-tool-lines-toggle
            @click="emit('toggle-lines')"
        >
            <Eye v-if="showTurnLines" class="size-3.5" />
            <EyeOff v-else class="size-3.5" />
            per-turn tools
        </button>
    </div>
</template>
