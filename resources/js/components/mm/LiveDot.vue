<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <span
        :class="
            cn(
                'mm-ping-dot inline-block size-1.5 rounded-full bg-current',
                props.class,
            )
        "
        aria-hidden="true"
    />
</template>
