<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref, watch } from 'vue';
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

const page = usePage();

/**
 * The `season:episode` of a deep link whose dialog the user closed. Async
 * visits (the deferred props, a webhook reload, any `only:` reload) are sent
 * to `window.location.href`, so one that left before the close answers with
 * the link's URL and puts the params back; this keeps that from reopening
 * the dialog. Only following a link again forgets it: a real (non-async)
 * visit to a URL that carries one (the `before` listener below). The
 * client-side cleanup replace is not a visit and fires no `before`.
 */
const dismissedDeepLink = ref<string | null>(null);

function deepLinkKey(url: string): string | null {
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
        return null;
    }

    return `${Number(season)}:${Number(episode)}`;
}

/**
 * Drops the deep-link params from the URL as a client-side replace (no
 * request, no remount, no new history entry), so a reload does not reopen
 * the dialog and following the same link again changes `page.url`.
 */
function dropDeepLinkParams(): void {
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
}

/**
 * Opens the replace dialog for the episode named by a `replace_season` /
 * `replace_episode` link (the chat's "Pick release"). Runs on mount and on
 * every URL change, so a link followed while already on this series works.
 * A dismissed link that an async response brings back is dropped again
 * instead of reopening. The watcher is `sync`: the cleanup replace and an
 * async response can both set the page before a `pre` flush, and the URL
 * would then go back to the link's without the watcher ever seeing it.
 */
watch(
    () => page.url,
    (url) => {
        const key = deepLinkKey(url);

        if (key === null) {
            return;
        }

        if (key === dismissedDeepLink.value) {
            dropDeepLinkParams();

            return;
        }

        const [season, episode] = key.split(':').map(Number);

        replaceTarget.value = { seasonNumber: season, episodeNumber: episode };
        replaceDialogOpen.value = true;
    },
    { immediate: true, flush: 'sync' },
);

watch(replaceDialogOpen, (open) => {
    if (open) {
        return;
    }

    const key = deepLinkKey(page.url);

    if (key !== null) {
        dismissedDeepLink.value = key;
    }

    dropDeepLinkParams();
});

let stopForgettingDismissedLink: (() => void) | null = null;

onMounted(() => {
    stopForgettingDismissedLink = router.on('before', (event) => {
        const visit = event.detail.visit;

        if (
            !visit.async &&
            !visit.prefetch &&
            deepLinkKey(visit.url.href) !== null
        ) {
            dismissedDeepLink.value = null;
        }
    });
});

onUnmounted(() => {
    stopForgettingDismissedLink?.();
    stopForgettingDismissedLink = null;
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
