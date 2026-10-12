<script setup lang="ts">
import { AlertTriangle } from '@lucide/vue';
import { computed } from 'vue';
import { Field } from '@/components/mm';
import type { ActionRequestResource } from '@/typefinder/resources/ActionRequestResource';
import {
    failureMessage,
    failureReasonLabel,
    isUncertainOutcome,
    uncertainOutcomeWarning,
} from './failure';

const props = defineProps<{
    request: ActionRequestResource;
    /** The "check <Service> before retrying" callout is for people who can retry. */
    showOutcomeWarning: boolean;
}>();

const reasonLabel = computed(() => failureReasonLabel(props.request.result));
const message = computed(() => failureMessage(props.request.result));
const warning = computed(() =>
    props.showOutcomeWarning && isUncertainOutcome(props.request.result)
        ? uncertainOutcomeWarning(props.request)
        : null,
);
</script>

<template>
    <div class="flex flex-col gap-3.5" data-action-failure>
        <div
            v-if="warning"
            class="flex gap-2.5 rounded-lg border border-warning/30 bg-warning/10 p-3 text-xs text-warning"
            data-action-failure-uncertain
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
            <span>{{ warning }}</span>
        </div>
        <Field label="Failure">
            <div class="flex flex-col gap-1">
                <span
                    class="text-[13px] font-medium"
                    data-action-failure-reason
                    >{{ reasonLabel }}</span
                >
                <span
                    v-if="message"
                    class="text-[12.5px] whitespace-pre-line text-muted-foreground"
                    data-action-failure-message
                    >{{ message }}</span
                >
            </div>
        </Field>
    </div>
</template>
