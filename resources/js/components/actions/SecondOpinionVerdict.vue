<script setup lang="ts">
import {
    ShieldAlert,
    ShieldCheck,
    ShieldOff,
    ShieldQuestion,
} from '@lucide/vue';
import { computed } from 'vue';
import { Pill } from '@/components/mm';
import type { ActionRequestResource } from '@/typefinder/resources/ActionRequestResource';

const props = defineProps<{
    verdict: NonNullable<ActionRequestResource['second_opinion']>;
}>();

const state = computed<'agrees' | 'disagrees' | 'failed' | 'skipped'>(() => {
    if ('skipped' in props.verdict) {
        return 'skipped';
    }

    if (props.verdict.failed) {
        return 'failed';
    }

    return props.verdict.agrees ? 'agrees' : 'disagrees';
});

const heading = computed(
    () =>
        ({
            agrees: 'Second opinion: agrees',
            disagrees: 'Second opinion: disagrees',
            failed: 'Second opinion: check failed',
            skipped: 'Second opinion skipped',
        })[state.value],
);

const detail = computed(() => {
    if ('skipped' in props.verdict) {
        return '';
    }

    if (props.verdict.reason) {
        return props.verdict.reason;
    }

    return props.verdict.confidence === null
        ? ''
        : `Classifier confidence ${Math.round(props.verdict.confidence * 100)}%`;
});
</script>

<template>
    <div
        class="flex gap-2.5 rounded-lg border border-border bg-bg-elev p-3"
        data-action-second-opinion
        :data-second-opinion-state="state"
    >
        <ShieldCheck
            v-if="state === 'agrees'"
            class="mt-0.5 size-4 shrink-0 text-success"
        />
        <ShieldAlert
            v-else-if="state === 'disagrees'"
            class="mt-0.5 size-4 shrink-0 text-warning"
        />
        <ShieldOff
            v-else-if="state === 'skipped'"
            class="mt-0.5 size-4 shrink-0 text-fg-subtle"
        />
        <ShieldQuestion v-else class="mt-0.5 size-4 shrink-0 text-fg-subtle" />
        <div class="flex flex-col gap-1">
            <span
                class="text-[11px] font-medium tracking-wide text-fg-subtle uppercase"
                >{{ heading }}</span
            >
            <span v-if="detail" class="text-[12.5px] text-muted-foreground">{{
                detail
            }}</span>
            <Pill
                v-if="verdict.override_event"
                class="w-fit"
                data-second-opinion-override-event
            >
                Per-event rule: {{ verdict.override_event }}
            </Pill>
        </div>
    </div>
</template>
