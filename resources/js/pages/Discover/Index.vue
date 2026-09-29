<script setup lang="ts">
import { Deferred, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import DiscoverController from '@/actions/App/Http/Controllers/Media/DiscoverController';
import {
    DiscoverRow,
    DiscoverRowSkeleton,
    TitleDetailSheet,
} from '@/components/discover';
import { dashboard } from '@/routes';
import type { DiscoverRowPayload, DiscoverTitle, RequestingContext } from '@/types';

type RowProp = 'trending' | 'popularMovies' | 'popularTv' | 'upcoming';

const props = defineProps<{
    seerr: { connected: boolean };
    trending?: DiscoverRowPayload;
    popularMovies?: DiscoverRowPayload;
    popularTv?: DiscoverRowPayload;
    upcoming?: DiscoverRowPayload;
    requesting?: RequestingContext;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Discover', href: DiscoverController.index.url() },
        ],
    },
});

const ROWS: { prop: RowProp; heading: string }[] = [
    { prop: 'trending', heading: 'Trending' },
    { prop: 'popularMovies', heading: 'Popular movies' },
    { prop: 'popularTv', heading: 'Popular TV' },
    { prop: 'upcoming', heading: 'Upcoming' },
];

const sheetOpen = ref(false);
const selected = ref<DiscoverTitle | null>(null);

function openTitle(item: DiscoverTitle): void {
    selected.value = item;
    sheetOpen.value = true;
}
</script>

<template>
    <Head title="Discover" />

    <div class="flex flex-col gap-6 p-5">
        <div>
            <h1 class="text-[22px] leading-tight font-semibold tracking-tight">Discover</h1>
            <p class="mt-1 text-[13px] text-muted-foreground">
                Find something to watch and request it.
            </p>
        </div>

        <div
            v-if="!props.seerr.connected"
            class="rounded-xl border border-border bg-card p-6"
            data-discover-unavailable
        >
            <p class="text-[14px] font-semibold">Discover needs Seerr</p>
            <p class="mt-1 text-[13px] text-muted-foreground">
                No Seerr connection is configured, so there is nothing to browse or request yet. Ask an admin to connect Seerr.
            </p>
        </div>

        <template v-else>
            <Deferred v-for="row in ROWS" :key="row.prop" :data="row.prop">
                <template #fallback>
                    <DiscoverRowSkeleton :heading="row.heading" />
                </template>
                <DiscoverRow
                    :heading="row.heading"
                    :slug="row.prop"
                    :row="props[row.prop] ?? { results: [], error: null }"
                    @open="openTitle"
                />
            </Deferred>
        </template>
    </div>

    <TitleDetailSheet v-model:open="sheetOpen" :item="selected" :requesting="props.requesting" />
</template>
