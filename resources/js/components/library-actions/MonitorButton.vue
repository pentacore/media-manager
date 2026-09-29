<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Bookmark, BookmarkX } from '@lucide/vue';
import { ref } from 'vue';
import MediaActionController from '@/actions/App/Http/Controllers/Library/MediaActionController';
import { Button } from '@/components/ui/button';

const props = withDefaults(
    defineProps<{
        service: 'sonarr' | 'radarr';
        connectionId: number;
        monitored: boolean;
        itemId?: number | null;
        seriesId?: number | null;
        episodeIds?: number[];
        seasonNumber?: number | null;
        label?: string | null;
    }>(),
    { itemId: null, seriesId: null, episodeIds: () => [], seasonNumber: null, label: null },
);

const busy = ref(false);

function toggle(): void {
    if (busy.value) {
        return;
    }

    busy.value = true;
    const options = {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
        },
    };

    if (props.episodeIds.length > 0 && props.seriesId !== null) {
        router.post(
            MediaActionController.monitorEpisodes.url(),
            {
                service_connection_id: props.connectionId,
                series_id: props.seriesId,
                episode_ids: props.episodeIds,
                season_number: props.seasonNumber,
                monitored: !props.monitored,
            },
            options,
        );

        return;
    }

    router.post(
        MediaActionController.monitor.url(),
        {
            service: props.service,
            service_connection_id: props.connectionId,
            item_id: props.itemId,
            monitored: !props.monitored,
        },
        options,
    );
}
</script>

<template>
    <Button
        variant="outline"
        size="sm"
        class="h-7 gap-1.5 text-xs"
        :disabled="busy"
        :aria-pressed="monitored"
        data-monitor-toggle
        @click.stop="toggle"
    >
        <Bookmark v-if="monitored" class="size-3.5" />
        <BookmarkX v-else class="size-3.5" />
        {{ label ?? (monitored ? 'Monitored' : 'Unmonitored') }}
    </Button>
</template>
