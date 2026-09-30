<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { ref } from 'vue';
import MediaActionController from '@/actions/App/Http/Controllers/Library/MediaActionController';
import { Button } from '@/components/ui/button';
import type { MediaSearchCommand } from '@/types';

const props = withDefaults(
    defineProps<{
        service: 'sonarr' | 'radarr';
        connectionId: number;
        command: MediaSearchCommand;
        seriesId?: number | null;
        seasonNumber?: number | null;
        episodeIds?: number[];
        movieIds?: number[];
        label?: string;
    }>(),
    {
        seriesId: null,
        seasonNumber: null,
        episodeIds: () => [],
        movieIds: () => [],
        label: 'Search',
    },
);

const busy = ref(false);

function start(): void {
    if (busy.value) {
        return;
    }

    busy.value = true;
    router.post(
        MediaActionController.search.url(),
        {
            service: props.service,
            service_connection_id: props.connectionId,
            command: props.command,
            series_id: props.seriesId,
            season_number: props.seasonNumber,
            episode_ids: props.episodeIds,
            movie_ids: props.movieIds,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}
</script>

<template>
    <Button
        variant="outline"
        size="sm"
        class="h-7 gap-1.5 text-xs"
        :disabled="busy"
        data-search-now
        @click.stop="start"
    >
        <Search class="size-3.5" />
        {{ label }}
    </Button>
</template>
