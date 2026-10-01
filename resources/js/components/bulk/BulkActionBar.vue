<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed, useId } from 'vue';
import { Button } from '@/components/ui/button';

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

defineSlots<{
    default(props: { disabled: boolean }): unknown;
}>();

const overLimitId = useId();
const overLimit = computed(() => props.count > props.max);
const disabled = computed(
    () => props.busy || props.count === 0 || overLimit.value,
);
</script>

<template>
    <!-- Stays mounted while a run is in flight, so the action component that
         owns the request can't be unmounted (and re-mounted idle) mid-run. -->
    <div
        v-if="count > 0 || busy"
        role="region"
        aria-label="Bulk actions"
        class="sticky bottom-4 z-20 flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card px-4 py-2.5 shadow-lg"
        data-bulk-bar
    >
        <span class="text-[13px] font-medium" aria-live="polite" data-bulk-count
            >{{ count }} selected</span
        >
        <span
            v-if="overLimit"
            :id="overLimitId"
            class="text-[12px] text-destructive"
            data-bulk-over-limit
        >
            Bulk actions take at most {{ max }} at a time — narrow the
            selection.
        </span>
        <div
            class="flex flex-wrap items-center gap-2"
            :aria-describedby="overLimit ? overLimitId : undefined"
            data-bulk-actions
        >
            <slot :disabled="disabled" />
        </div>
        <Button
            variant="ghost"
            size="sm"
            class="ml-auto h-7 gap-1 text-xs"
            :disabled="busy"
            data-bulk-clear
            @click="emit('clear')"
        >
            <X class="size-3.5" />Clear
        </Button>
    </div>
</template>
