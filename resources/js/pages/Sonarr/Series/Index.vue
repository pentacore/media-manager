<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Database,
    ExternalLink,
    Filter,
    Layers,
    Plus,
    RefreshCcw,
    Search,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import SeriesController from '@/actions/App/Http/Controllers/Media/SeriesController';
import { Pill, Poster, SvcChip } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useRealtimeReload } from '@/composables/useRealtimeReload';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';

interface QualityProfile {
    id: number;
    name: string;
}

interface SeriesImage {
    coverType: string;
    remoteUrl?: string;
    url?: string;
}

interface Series {
    id: number;
    title: string;
    title_slug: string | null;
    year: number | null;
    status: string | null;
    monitored: boolean;
    quality_profile_id: number | null;
    season_count: number;
    size_on_disk: number;
    episode_file_count: number;
    episode_count: number;
    images: SeriesImage[];
}

const props = defineProps<{
    connection: { url: string };
    series?: Series[];
    qualityProfiles?: QualityProfile[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Media', href: dashboard().url },
            { title: 'TV Series', href: SeriesController.index.url() },
        ],
    },
});

const { subscribe: subscribeReload } = useRealtimeReload<{
    service_type: string | null;
}>({
    channel: 'dashboard',
    event: 'WebhookReceived',
    only: ['series'],
    filter: (event) => event.service_type === 'sonarr',
});

onMounted(subscribeReload);

const view = ref<'grid' | 'table'>('grid');
const monitoredFilter = ref<'all' | 'monitored' | 'unmonitored'>('all');
const query = ref('');

const visible = computed<Series[]>(() => {
    if (!props.series) {
return [];
}

    return props.series.filter((s) => {
        if (monitoredFilter.value === 'monitored' && !s.monitored) {
return false;
}

        if (monitoredFilter.value === 'unmonitored' && s.monitored) {
return false;
}

        if (
            query.value &&
            !s.title.toLowerCase().includes(query.value.toLowerCase())
        ) {
            return false;
        }

        return true;
    });
});

const counts = computed(() => {
    const all = props.series?.length ?? 0;
    const monitored =
        props.series?.filter((s) => s.monitored).length ?? 0;

    return { all, monitored, unmonitored: all - monitored };
});

const totalSize = computed(() => {
    const sum = (props.series ?? []).reduce(
        (acc, s) => acc + (s.size_on_disk ?? 0),
        0,
    );

    return formatSize(sum);
});

function formatSize(bytes: number): string {
    if (!bytes || bytes <= 0) {
return '0 B';
}

    const tb = bytes / 1024 ** 4;

    if (tb >= 1) {
return `${tb.toFixed(1)} TB`;
}

    const gb = bytes / 1024 ** 3;

    if (gb >= 1) {
return `${gb.toFixed(1)} GB`;
}

    const mb = bytes / 1024 ** 2;

    return `${mb.toFixed(0)} MB`;
}

function qualityName(id: number | null): string {
    if (id === null || !props.qualityProfiles) {
return '—';
}

    return (
        props.qualityProfiles.find((profile) => profile.id === id)?.name ?? '—'
    );
}

function progressPct(item: Series): number {
    if (!item.episode_count) {
return 0;
}

    return Math.min(
        100,
        (item.episode_file_count / item.episode_count) * 100,
    );
}

function sonarrSeriesUrl(slug: string | null): string | null {
    if (!slug) {
return null;
}

    return `${props.connection.url}/series/${slug}`;
}
</script>

<template>
    <Head title="TV Series" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero -->
        <div class="flex items-end justify-between gap-3">
            <div>
                <div class="mb-1.5 flex items-center gap-2">
                    <SvcChip id="sonarr" />
                    <span class="text-fg-subtle">/</span>
                    <span class="text-[13px] text-muted-foreground"
                        >TV Series</span
                    >
                </div>
                <h1 class="text-[22px] font-semibold tracking-tight">
                    Library
                </h1>
                <p class="mt-1 text-[13px] text-muted-foreground">
                    <template v-if="series">
                        {{ counts.all }} series ·
                        <span class="font-mono-tabular">{{ totalSize }}</span>
                        on disk · {{ counts.monitored }} monitored
                    </template>
                    <Skeleton v-else class="inline-block h-4 w-40" />
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button variant="outline" size="sm" class="h-7 gap-1.5 text-xs">
                    <RefreshCcw class="size-3.5" />Sync
                </Button>
                <Link :href="SeriesController.create.url()">
                    <Button size="sm" class="h-7 gap-1.5 text-xs">
                        <Plus class="size-3.5" />Add series
                    </Button>
                </Link>
            </div>
        </div>

        <!-- Toolbar -->
        <div
            class="flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card p-3"
        >
            <div
                class="flex h-8 flex-1 min-w-[240px] items-center gap-2 rounded-md border border-border bg-bg-elev px-3"
            >
                <Search class="size-3.5 text-fg-subtle" />
                <input
                    v-model="query"
                    :placeholder="`Search ${counts.all} series…`"
                    class="flex-1 bg-transparent text-[13px] outline-none placeholder:text-fg-subtle"
                />
                <kbd
                    class="font-mono-tabular rounded border border-border bg-card px-1 text-[10px] text-muted-foreground"
                    >/</kbd
                >
            </div>

            <div
                class="flex items-center gap-1 rounded-md border border-border bg-bg-elev p-0.5"
            >
                <button
                    v-for="opt in [
                        ['all', 'All', counts.all],
                        ['monitored', 'Monitored', counts.monitored],
                        ['unmonitored', 'Unmonitored', counts.unmonitored],
                    ] as const"
                    :key="opt[0]"
                    type="button"
                    :class="
                        cn(
                            'inline-flex h-6 items-center rounded px-2 text-xs font-medium transition-colors',
                            monitoredFilter === opt[0]
                                ? 'bg-accent text-accent-foreground'
                                : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                        )
                    "
                    @click="monitoredFilter = opt[0]"
                >
                    {{ opt[1] }}
                    <span
                        class="font-mono-tabular ml-1 text-[10.5px] opacity-70"
                        >{{ opt[2] }}</span
                    >
                </button>
            </div>

            <Button variant="outline" size="sm" class="h-7 gap-1.5 text-xs">
                <Filter class="size-3.5" />Profile
            </Button>

            <div
                class="flex items-center gap-1 rounded-md border border-border bg-bg-elev p-0.5"
            >
                <button
                    type="button"
                    :class="
                        cn(
                            'inline-flex size-6 items-center justify-center rounded transition-colors',
                            view === 'grid'
                                ? 'bg-accent text-accent-foreground'
                                : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                        )
                    "
                    @click="view = 'grid'"
                >
                    <Layers class="size-3.5" />
                </button>
                <button
                    type="button"
                    :class="
                        cn(
                            'inline-flex size-6 items-center justify-center rounded transition-colors',
                            view === 'table'
                                ? 'bg-accent text-accent-foreground'
                                : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                        )
                    "
                    @click="view = 'table'"
                >
                    <Database class="size-3.5" />
                </button>
            </div>
        </div>

        <!-- Grid -->
        <div
            v-if="view === 'grid' && series"
            class="grid gap-[18px]"
            style="grid-template-columns: repeat(auto-fill, minmax(160px, 1fr))"
        >
            <Link
                v-for="item in visible"
                :key="item.id"
                :href="SeriesController.show.url(item.id)"
                class="group flex flex-col gap-2"
            >
                <div class="relative">
                    <Poster
                        :hint="item.title.toLowerCase().slice(0, 12)"
                        size="full"
                    />
                    <Pill
                        v-if="!item.monitored"
                        class="absolute bottom-2 left-2 border-transparent bg-black/55 text-white/70"
                    >
                        Unmonitored
                    </Pill>
                </div>
                <div>
                    <div
                        class="text-[13px] leading-tight font-medium text-pretty group-hover:text-accent"
                    >
                        {{ item.title }}
                    </div>
                    <div
                        class="font-mono-tabular mt-0.5 flex justify-between text-[10.5px] text-fg-subtle"
                    >
                        <span>{{ item.year ?? '—' }}</span>
                        <span
                            >{{ item.episode_file_count }} /
                            {{ item.episode_count }}</span
                        >
                    </div>
                    <div
                        class="mt-1.5 h-1 overflow-hidden rounded-full bg-bg-elev"
                    >
                        <div
                            class="h-full rounded-full bg-accent"
                            :style="{ width: `${progressPct(item)}%` }"
                        />
                    </div>
                </div>
            </Link>
            <div
                v-if="visible.length === 0"
                class="col-span-full py-8 text-center text-sm text-fg-subtle"
            >
                No series match these filters.
            </div>
        </div>

        <!-- Table -->
        <div
            v-else-if="view === 'table' && series"
            class="overflow-hidden rounded-xl border border-border bg-card"
        >
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            v-for="h in [
                                'Series',
                                'Year',
                                'Status',
                                'Quality',
                                'Episodes',
                                'Size',
                                '',
                            ]"
                            :key="h"
                            class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            {{ h }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in visible"
                        :key="item.id"
                        class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                    >
                        <td class="px-3 py-2.5">
                            <span class="flex items-center gap-2.5">
                                <Poster
                                    :hint="item.title.toLowerCase().slice(0, 8)"
                                    size="sm"
                                />
                                <Link
                                    :href="SeriesController.show.url(item.id)"
                                    class="font-medium hover:text-accent"
                                    >{{ item.title }}</Link
                                >
                                <Pill
                                    v-if="!item.monitored"
                                    class="text-[10.5px]"
                                    >unmon</Pill
                                >
                            </span>
                        </td>
                        <td class="font-mono-tabular px-3 py-2.5">
                            {{ item.year ?? '—' }}
                        </td>
                        <td class="px-3 py-2.5 text-muted-foreground">
                            {{ item.status ?? '—' }}
                        </td>
                        <td class="font-mono-tabular px-3 py-2.5 text-[11.5px]">
                            {{ qualityName(item.quality_profile_id) }}
                        </td>
                        <td class="font-mono-tabular px-3 py-2.5">
                            {{ item.episode_file_count }} /
                            {{ item.episode_count }}
                        </td>
                        <td class="font-mono-tabular px-3 py-2.5">
                            {{ formatSize(item.size_on_disk) }}
                        </td>
                        <td class="px-3 py-2.5 text-right">
                            <a
                                v-if="sonarrSeriesUrl(item.title_slug)"
                                :href="
                                    sonarrSeriesUrl(item.title_slug) ??
                                    undefined
                                "
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex size-7 items-center justify-center rounded text-muted-foreground hover:bg-bg-hover hover:text-foreground"
                            >
                                <ExternalLink class="size-3.5" />
                            </a>
                        </td>
                    </tr>
                    <tr v-if="visible.length === 0">
                        <td
                            colspan="7"
                            class="px-3 py-8 text-center text-sm text-fg-subtle"
                        >
                            No series match these filters.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Skeleton loading -->
        <div
            v-else
            class="grid gap-[18px]"
            style="grid-template-columns: repeat(auto-fill, minmax(160px, 1fr))"
        >
            <div v-for="n in 8" :key="`skel-${n}`" class="flex flex-col gap-2">
                <Skeleton class="aspect-[2/3] w-full rounded-md" />
                <Skeleton class="h-3 w-3/4" />
                <Skeleton class="h-2 w-1/2" />
            </div>
        </div>
    </div>
</template>
