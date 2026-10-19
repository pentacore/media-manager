<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { PaginatorLink } from './types';

defineProps<{
    links: PaginatorLink[];
}>();

const emit = defineEmits<{
    go: [url: string | null];
}>();
</script>

<template>
    <div class="flex flex-wrap items-center gap-2" data-action-pagination>
        <Button
            v-for="(link, index) in links"
            :key="index"
            variant="outline"
            size="sm"
            :disabled="!link.url"
            :class="link.active ? 'bg-accent text-accent-foreground' : ''"
            @click="emit('go', link.url)"
        >
            <span v-html="link.label" />
        </Button>
    </div>
</template>
