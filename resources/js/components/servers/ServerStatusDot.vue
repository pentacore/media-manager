<script setup lang="ts">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { Pill } from '@/components/mm';
import type { ServerStatus } from './types';

const props = defineProps<{
    status: ServerStatus;
    label: string;
    class?: HTMLAttributes['class'];
}>();

const VARIANTS: Record<ServerStatus, 'default' | 'ok' | 'warn' | 'danger'> = {
    pending: 'default',
    ok: 'ok',
    incident: 'danger',
    silent: 'warn',
};

const variant = computed(() => VARIANTS[props.status] ?? 'default');
</script>

<template>
    <Pill
        :variant="variant"
        dot
        :data-server-status="status"
        :class="props.class"
    >
        {{ label }}
    </Pill>
</template>
