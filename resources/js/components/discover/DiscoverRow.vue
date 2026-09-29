<script setup lang="ts">
import { AlertCircle } from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import type { DiscoverRowPayload, DiscoverTitle } from '@/types';
import TitleCard from './TitleCard.vue';

defineProps<{ heading: string; slug: string; row: DiscoverRowPayload }>();

const emit = defineEmits<{ open: [item: DiscoverTitle] }>();
</script>

<template>
    <section class="space-y-2" :data-discover-row="slug">
        <h2 class="text-[15px] font-semibold tracking-tight">{{ heading }}</h2>
        <Alert v-if="row.error" variant="destructive" data-discover-row-error>
            <AlertCircle class="size-4" />
            <AlertTitle>{{ heading }} is unavailable</AlertTitle>
            <AlertDescription>{{ row.error }}</AlertDescription>
        </Alert>
        <p v-else-if="row.results.length === 0" class="text-[13px] text-muted-foreground">
            Nothing here right now.
        </p>
        <div v-else class="flex gap-3 overflow-x-auto pb-2">
            <TitleCard
                v-for="item in row.results"
                :key="`${item.media_type}-${item.tmdb_id}`"
                :item="item"
                @open="emit('open', item)"
            />
        </div>
    </section>
</template>
