<script setup lang="ts">
import { Check, ListChecks } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import type { ChoicesCard } from './types';

const props = defineProps<{
    card: ChoicesCard;
    /** Only the latest reply's card, with no turn in flight, takes a pick. */
    interactive: boolean;
    /** The labels already picked (highlighted once the card is locked). */
    chosen: string[] | null;
}>();

const emit = defineEmits<{
    (e: 'choose', labels: string[]): void;
}>();

/** The options ticked so far on a multi-select card. */
const picked = ref<string[]>([]);

function isChosen(label: string): boolean {
    return !props.interactive && (props.chosen ?? []).includes(label);
}

function toggle(label: string, checked: boolean): void {
    picked.value = checked
        ? [...picked.value, label]
        : picked.value.filter((value) => value !== label);
}

/** Confirm in the order the options are offered, not the order ticked. */
function confirmPicked(): void {
    emit(
        'choose',
        props.card.options
            .map((option) => option.label)
            .filter((label) => picked.value.includes(label)),
    );
}
</script>

<template>
    <div
        class="mb-2 rounded-lg border border-border bg-bg-elev p-2.5"
        data-choice-card
    >
        <div class="mb-2 flex items-center gap-1.5">
            <ListChecks class="size-3.5 shrink-0 text-fg-subtle" />
            <span class="text-[12.5px] font-medium">{{ card.question }}</span>
        </div>

        <div v-if="!card.multiple" class="flex flex-wrap gap-1.5">
            <Button
                v-for="(option, index) in card.options"
                :key="index"
                size="sm"
                variant="outline"
                :class="
                    cn(
                        'h-auto min-h-7 max-w-full flex-col items-start gap-0 py-1 text-left text-xs whitespace-normal',
                        isChosen(option.label) &&
                            'border-accent/28 bg-accent/18 text-accent disabled:opacity-100',
                    )
                "
                :disabled="!interactive"
                :data-choice-option="option.label"
                :data-choice-chosen="isChosen(option.label) || undefined"
                @click="emit('choose', [option.label])"
            >
                <span class="inline-flex items-center gap-1.5">
                    <Check v-if="isChosen(option.label)" class="size-3" />
                    {{ option.label }}
                </span>
                <span
                    v-if="option.description"
                    class="block text-[11px] font-normal text-fg-subtle"
                    data-choice-description
                    >{{ option.description }}</span
                >
            </Button>
        </div>

        <div v-else class="flex flex-col gap-1.5">
            <label
                v-for="(option, index) in card.options"
                :key="index"
                :class="
                    cn(
                        'flex items-start gap-2 rounded-md px-1.5 py-1 text-[12.5px]',
                        isChosen(option.label) && 'bg-accent/18 text-accent',
                    )
                "
            >
                <Checkbox
                    class="mt-0.5"
                    :model-value="
                        interactive
                            ? picked.includes(option.label)
                            : isChosen(option.label)
                    "
                    :disabled="!interactive"
                    :data-choice-toggle="option.label"
                    :data-choice-chosen="isChosen(option.label) || undefined"
                    @update:model-value="
                        (value) => toggle(option.label, value === true)
                    "
                />
                <span class="flex min-w-0 flex-col">
                    <span class="inline-flex items-center gap-1.5">
                        {{ option.label }}
                        <Check v-if="isChosen(option.label)" class="size-3" />
                    </span>
                    <span
                        v-if="option.description"
                        class="block text-[11px] text-fg-subtle"
                        data-choice-description
                        >{{ option.description }}</span
                    >
                </span>
            </label>
            <div>
                <Button
                    size="sm"
                    class="mt-1 h-7 text-xs"
                    :disabled="!interactive || picked.length === 0"
                    data-choice-confirm
                    @click="confirmPicked"
                >
                    Confirm
                </Button>
            </div>
        </div>
    </div>
</template>
