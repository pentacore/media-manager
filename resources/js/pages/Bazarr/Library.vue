<script setup lang="ts">
import { Deferred, Head } from '@inertiajs/vue3';
import LibraryController from '@/actions/App/Http/Controllers/Bazarr/LibraryController';
import OverviewController from '@/actions/App/Http/Controllers/Bazarr/OverviewController';
import SubtitleTabs from '@/components/bazarr/SubtitleTabs.vue';
import { dashboard } from '@/routes';

interface SubtitleTrack {
    language: string;
    forced?: boolean;
    hearing_impaired?: boolean;
}

interface SubtitleItem {
    media_type: 'episode' | 'movie';
    media_id: number;
    title: string;
    scope?: string;
    subtitle_tracks: SubtitleTrack[];
    missing_languages: string[];
}

interface Inventory {
    data: SubtitleItem[];
    total: number;
    partial: boolean;
    errors: string[];
}

defineProps<{
    connections: { id: number; name: string }[];
    selected_connection_id: number | null;
    requires_connection_selection: boolean;
    library?: Inventory | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Media', href: dashboard().url },
            { title: 'Subtitles', href: OverviewController.url() },
            { title: 'Library', href: LibraryController.url() },
        ],
    },
});
</script>

<template>
    <div class="space-y-6 p-4 sm:p-6">
        <Head title="Subtitle library" />
        <SubtitleTabs
            active="library"
            :connections="connections"
            :selected-connection-id="selected_connection_id"
        />

        <div
            v-if="requires_connection_selection"
            class="rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
        >
            Select a Bazarr connection to load its library.
        </div>
        <Deferred v-else-if="selected_connection_id" data="library">
            <template #fallback>
                <div
                    data-test="subtitle-library-skeleton"
                    class="animate-pulse space-y-3"
                >
                    <div
                        v-for="index in 4"
                        :key="index"
                        class="h-20 rounded-xl bg-muted"
                    />
                </div>
            </template>

            <div
                v-if="library?.partial"
                class="mb-4 rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
            >
                {{ library.errors.join(' ') }}
            </div>
            <div
                v-if="library && library.data.length === 0"
                class="rounded-xl border border-border bg-card p-8 text-center"
            >
                <p class="font-medium">No subtitle inventory found</p>
                <p class="text-sm text-muted-foreground">
                    Check the mapped Sonarr and Radarr connections.
                </p>
            </div>
            <div v-else class="grid gap-4 md:grid-cols-2">
                <article
                    v-for="item in library?.data ?? []"
                    :key="`${item.media_type}-${item.media_id}`"
                    class="rounded-xl border border-border bg-card p-4"
                >
                    <p class="font-medium">{{ item.title }}</p>
                    <p class="text-xs text-muted-foreground capitalize">
                        {{ item.media_type }} · {{ item.scope ?? 'movie' }}
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span
                            v-for="track in item.subtitle_tracks"
                            :key="`${track.language}-${track.forced}-${track.hearing_impaired}`"
                            class="rounded-full bg-muted px-2 py-1 text-xs"
                        >
                            {{ track.language }}
                            <template v-if="track.forced"> · forced</template>
                            <template v-if="track.hearing_impaired">
                                · HI
                            </template>
                        </span>
                        <span
                            v-if="item.subtitle_tracks.length === 0"
                            class="text-xs text-muted-foreground"
                        >
                            No subtitle tracks
                        </span>
                    </div>
                </article>
            </div>
        </Deferred>
    </div>
</template>
