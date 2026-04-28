<script setup lang="ts">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import Pill from '@/components/mm/Pill.vue';

type Status =
    | 'pending'
    | 'approved'
    | 'executing'
    | 'completed'
    | 'failed'
    | 'rejected'
    | 'available'
    | 'declined'
    | 'healthy'
    | 'degraded'
    | 'down'
    | 'ok'
    | 'warn'
    | 'queued'
    | string;

const props = defineProps<{
    status: Status;
    label?: string;
    class?: HTMLAttributes['class'];
}>();

const meta = computed(() => {
    switch (props.status) {
        case 'pending':
            return { variant: 'warn' as const, label: 'Pending' };
        case 'queued':
            return { variant: 'warn' as const, label: 'Queued' };
        case 'approved':
            return { variant: 'info' as const, label: 'Approved' };
        case 'executing':
            return { variant: 'info' as const, label: 'Executing' };
        case 'completed':
        case 'available':
        case 'healthy':
        case 'ok':
            return {
                variant: 'ok' as const,
                label:
                    props.status.charAt(0).toUpperCase() +
                    props.status.slice(1),
            };
        case 'failed':
        case 'rejected':
        case 'declined':
        case 'down':
            return {
                variant: 'danger' as const,
                label:
                    props.status.charAt(0).toUpperCase() +
                    props.status.slice(1),
            };
        case 'degraded':
        case 'warn':
            return { variant: 'warn' as const, label: 'Degraded' };
        default:
            return { variant: 'default' as const, label: props.status };
    }
});
</script>

<template>
    <Pill :variant="meta.variant" dot :class="props.class">
        {{ label ?? meta.label }}
    </Pill>
</template>
