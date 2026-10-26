<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { onMounted, ref, watch } from 'vue';
import SeriesController from '@/actions/App/Http/Controllers/Media/SeriesController';
import { InteractiveSearchDialog } from '@/components/library-actions';
import ReplaceFileDialog from '@/components/media-replacement/ReplaceFileDialog.vue';
import {
    SeriesOverview,
    SeriesSeasons,
    SeriesToolbar,
} from '@/components/series';
import type {
    Episode,
    QualityProfile,
    SeriesDetail,
} from '@/components/series';
import { useRealtimeReload } from '@/composables/useRealtimeReload';
import { dashboard } from '@/routes';
import type { UpstreamList } from '@/types';

const props = defineProps<{
    connection: { url: string | null };
    service_connection_id: number;
    series: SeriesDetail;
    episodes?: UpstreamList<Episode>;
    qualityProfiles?: UpstreamList<QualityProfile>;
    /** Viewers only: the current profile's name; null when unknown. */
    qualityProfileName?: string | null;
}>();

// WebhookEventProcessed fires after the queued handler cleared this
// connection's Sonarr cache, so the reload reads the fresh series and
// episodes; a webhook for another connection leaves this title alone.
const { subscribe: subscribeReload } = useRealtimeReload<{
    service_connection_id: number | null;
    service_type: string | null;
}>({
    channel: 'dashboard',
    event: 'WebhookEventProcessed',
    only: ['series', 'episodes'],
    filter: (event) =>
        event.service_type === 'sonarr' &&
        event.service_connection_id === props.service_connection_id,
});

onMounted(subscribeReload);

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Series', href: SeriesController.index.url() },
        ],
    },
});

// Kept here, not in the seasons: a webhook reload re-renders the sections,
// and an expanded season must stay expanded.
const openSeasons = ref<Record<number, boolean>>({});
const replaceDialogOpen = ref(false);
const replaceTarget = ref<{
    seasonNumber: number;
    episodeNumber: number;
} | null>(null);

function openReplaceDialog(episode: Episode): void {
    replaceTarget.value = {
        seasonNumber: episode.season_number,
        episodeNumber: episode.episode_number,
    };
    replaceDialogOpen.value = true;
}

/**
 * Opens the replace dialog for the episode named by a `replace_season` /
 * `replace_episode` link (the chat's "Pick release"). Runs on mount and on
 * every URL change, so a link followed while already on this series works;
 * unrelated prop reloads keep the URL and so never reopen it.
 */
const page = usePage();

watch(
    () => page.url,
    (url) => {
        const query = new URL(url, 'http://localhost').searchParams;
        const season = query.get('replace_season');
        const episode = query.get('replace_episode');

        if (
            season === null ||
            episode === null ||
            !/^\d+$/.test(season) ||
            !/^\d+$/.test(episode) ||
            Number(episode) < 1
        ) {
            return;
        }

        replaceTarget.value = {
            seasonNumber: Number(season),
            episodeNumber: Number(episode),
        };
        replaceDialogOpen.value = true;
    },
    { immediate: true },
);

/**
 * Closing the dialog drops the deep-link params from the URL, as a client-side
 * replace (no request, no remount), so a reload does not reopen it and
 * following the same link again changes `page.url` and reopens it.
 */
watch(replaceDialogOpen, (open) => {
    if (open) {
        return;
    }

    const url = new URL(page.url, 'http://localhost');

    if (
        !url.searchParams.has('replace_season') &&
        !url.searchParams.has('replace_episode')
    ) {
        return;
    }

    url.searchParams.delete('replace_season');
    url.searchParams.delete('replace_episode');

    router.replace({
        url: url.pathname + url.search + url.hash,
        preserveState: true,
        preserveScroll: true,
    });
});

const interactiveOpen = ref(false);
const interactive = ref<{
    heading: string;
    seasonNumber: number | null;
    episodeId: number | null;
} | null>(null);

function openInteractive(
    heading: string,
    seasonNumber: number | null,
    episodeId: number | null,
): void {
    interactive.value = { heading, seasonNumber, episodeId };
    interactiveOpen.value = true;
}

function toggleSeason(seasonNumber: number) {
    openSeasons.value[seasonNumber] = !openSeasons.value[seasonNumber];
}
</script>

<template>
    <Head :title="series.title" />

    <div class="flex flex-col gap-6 p-5">
        <SeriesToolbar
            :series="series"
            :connection-id="service_connection_id"
            :connection-url="connection.url"
        />

        <SeriesOverview
            :series="series"
            :connection-id="service_connection_id"
            :quality-profiles="qualityProfiles"
            :quality-profile-name="qualityProfileName"
        />

        <SeriesSeasons
            :series="series"
            :episodes="episodes"
            :connection-id="service_connection_id"
            :open-seasons="openSeasons"
            @toggle="toggleSeason"
            @interactive="openInteractive"
            @replace="openReplaceDialog"
        />
    </div>

    <ReplaceFileDialog
        v-if="replaceTarget"
        v-model:open="replaceDialogOpen"
        service="sonarr"
        :connection-id="service_connection_id"
        :item-id="series.id"
        :season-number="replaceTarget.seasonNumber"
        :episode-number="replaceTarget.episodeNumber"
    />

    <InteractiveSearchDialog
        v-if="interactive"
        v-model:open="interactiveOpen"
        service="sonarr"
        :connection-id="service_connection_id"
        :item-id="series.id"
        :season-number="interactive.seasonNumber"
        :episode-id="interactive.episodeId"
        :heading="interactive.heading"
    />
</template>
