<script setup lang="ts">
import { computed, useId } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { AiReasoningLevel } from '@/typefinder';

const props = withDefaults(
    defineProps<{
        levels: Array<{ label: string; value: AiReasoningLevel }>;
        inheritLabel?: string;
        accepted?: AiReasoningLevel[] | null;
        disabledHint?: string | null;
        id?: string;
        ariaLabel?: string;
        disabled?: boolean;
    }>(),
    {
        inheritLabel: undefined,
        accepted: null,
        disabledHint: null,
        id: undefined,
        ariaLabel: undefined,
        disabled: false,
    },
);

const level = defineModel<AiReasoningLevel | null>({ default: null });

/**
 * `ariaLabel` names the trigger through an sr-only span plus the trigger
 * itself, so the selected level stays in the accessible name.
 */
const generatedId = useId();
const triggerId = computed(() => props.id ?? `${generatedId}-trigger`);
const nameId = `${generatedId}-name`;

/** Select items cannot carry null, so "inherit" uses a sentinel. */
const INHERIT = '__inherit__';

const selected = computed<string>({
    get: () => level.value ?? (props.inheritLabel ? INHERIT : ''),
    set: (value: string) => {
        level.value = value === INHERIT ? null : (value as AiReasoningLevel);
    },
});

/**
 * Levels the model is known not to accept stay selectable (the server clamps
 * them to the nearest accepted level), but are labelled so the admin knows
 * what will really be sent.
 */
function clamped(value: AiReasoningLevel): boolean {
    return (
        props.accepted !== null &&
        value !== 'provider_default' &&
        !props.accepted.includes(value)
    );
}
</script>

<template>
    <div data-reasoning-select>
        <span
            v-if="ariaLabel"
            :id="nameId"
            class="sr-only"
            aria-hidden="true"
            :data-select-name="ariaLabel"
            >{{ ariaLabel }}</span
        >
        <Select
            v-model="selected"
            :disabled="disabled || disabledHint !== null"
        >
            <SelectTrigger
                :id="triggerId"
                :aria-labelledby="
                    ariaLabel ? `${nameId} ${triggerId}` : undefined
                "
                class="h-8 max-w-[220px] text-sm"
            >
                <SelectValue placeholder="Reasoning" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-if="inheritLabel"
                    :value="INHERIT"
                    data-reasoning-option="inherit"
                >
                    {{ inheritLabel }}
                </SelectItem>
                <SelectItem
                    v-for="option in levels"
                    :key="option.value"
                    :value="option.value"
                    :data-reasoning-option="option.value"
                >
                    {{ option.label }}
                    <span
                        v-if="clamped(option.value)"
                        class="text-muted-foreground"
                    >
                        (clamped)
                    </span>
                </SelectItem>
            </SelectContent>
        </Select>
        <p
            v-if="disabledHint"
            class="mt-1 text-[12px] text-muted-foreground"
            data-reasoning-hint
        >
            {{ disabledHint }}
        </p>
    </div>
</template>
