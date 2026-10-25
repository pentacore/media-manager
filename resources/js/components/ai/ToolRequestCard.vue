<script setup lang="ts">
import { Check, Wrench } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { ToolRequestCard } from './types';

const props = defineProps<{
    card: ToolRequestCard;
    /** Only the latest reply's card, with no turn in flight, takes an answer. */
    interactive: boolean;
    /** The answer already given ('load' or 'decline'), once the card is locked. */
    chosen: string | null;
}>();

const emit = defineEmits<{
    (e: 'answer', load: boolean): void;
}>();

function isChosen(answer: 'load' | 'decline'): boolean {
    return !props.interactive && props.chosen === answer;
}
</script>

<template>
    <div
        class="mb-2 rounded-lg border border-border bg-bg-elev p-2.5"
        data-tool-request-card
    >
        <div class="mb-2 flex items-start gap-1.5">
            <Wrench class="mt-0.5 size-3.5 shrink-0 text-fg-subtle" />
            <div class="flex min-w-0 flex-col gap-0.5">
                <span class="text-[12.5px] font-medium">{{ card.reason }}</span>
                <span
                    class="text-[11.5px] text-fg-subtle"
                    data-tool-request-groups
                    >{{
                        card.groups.map((group) => group.label).join(' & ')
                    }}</span
                >
            </div>
        </div>

        <div class="flex flex-wrap gap-1.5">
            <Button
                size="sm"
                variant="outline"
                :class="
                    cn(
                        'h-7 text-xs',
                        isChosen('load') &&
                            'border-accent/28 bg-accent/18 text-accent disabled:opacity-100',
                    )
                "
                :disabled="!interactive"
                data-tool-request-load
                :data-tool-request-chosen="isChosen('load') || undefined"
                @click="emit('answer', true)"
            >
                <Check v-if="isChosen('load')" class="size-3" />
                Load
            </Button>
            <Button
                size="sm"
                variant="outline"
                :class="
                    cn(
                        'h-7 text-xs',
                        isChosen('decline') &&
                            'border-accent/28 bg-accent/18 text-accent disabled:opacity-100',
                    )
                "
                :disabled="!interactive"
                data-tool-request-decline
                :data-tool-request-chosen="isChosen('decline') || undefined"
                @click="emit('answer', false)"
            >
                <Check v-if="isChosen('decline')" class="size-3" />
                No thanks
            </Button>
        </div>
    </div>
</template>
