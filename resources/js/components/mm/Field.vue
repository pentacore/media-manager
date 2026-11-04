<script setup lang="ts">
/**
 * A labelled field. With `for`, the label is a real `<label>` for that control
 * and gets the id `<for>-label` (the hint gets `<for>-hint`), so button-based
 * controls can point `aria-labelledby` at it. A row with several controls
 * passes `labelId` instead: the label stays a `<div>` with that id (a
 * `<label for>` may only point at a labelable element), the hint gets
 * `<labelId>-hint`, and the control group points `aria-labelledby` at it.
 */
defineProps<{
    label: string;
    hint?: string;
    for?: string;
    labelId?: string;
}>();
</script>

<template>
    <div class="grid gap-1.5">
        <label
            v-if="$props.for"
            :id="`${$props.for}-label`"
            :for="$props.for"
            class="block text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
        >
            {{ label }}
        </label>
        <div
            v-else
            :id="labelId"
            class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
        >
            {{ label }}
        </div>
        <div class="text-[13px]">
            <slot />
        </div>
        <div
            v-if="hint"
            :id="
                $props.for
                    ? `${$props.for}-hint`
                    : labelId
                      ? `${labelId}-hint`
                      : undefined
            "
            class="text-[12px] leading-snug text-muted-foreground"
        >
            {{ hint }}
        </div>
    </div>
</template>
