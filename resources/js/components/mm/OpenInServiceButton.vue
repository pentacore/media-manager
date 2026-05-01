<script setup lang="ts">
import { ExternalLink } from 'lucide-vue-next';
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
    <a
        v-if="href"
        :href="href"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex"
    >
        <Button variant="outline" size="sm" class="h-7 gap-1.5 text-xs">
            <ExternalLink class="size-3.5" />{{ label }}
        </Button>
    </a>
</template>
