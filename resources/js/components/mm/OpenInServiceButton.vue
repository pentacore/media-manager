<script setup lang="ts">
import { ExternalLink } from '@lucide/vue';
import { Button } from '@/components/ui/button';

withDefaults(
    defineProps<{
        href: string | null | undefined;
        label?: string;
    }>(),
    {
        label: 'Open in service',
    },
);
</script>

<template>
    <Button
        v-if="href"
        as-child
        variant="outline"
        size="sm"
        class="h-7 gap-1.5 text-xs"
        data-open-in-service
    >
        <a :href="href" target="_blank" rel="noopener noreferrer">
            <ExternalLink class="size-3.5" />{{ label }}
        </a>
    </Button>
</template>
