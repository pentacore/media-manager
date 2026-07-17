<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    Clock,
    Command,
    ExternalLink,
    Search as SearchIcon,
    X,
} from '@lucide/vue';
import { computed, onMounted, ref, useTemplateRef } from 'vue';
import SearchController from '@/actions/App/Http/Controllers/Media/SearchController';
import { Pill, Poster, StatusPill, SvcChip } from '@/components/mm';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Skeleton } from '@/components/ui/skeleton';
import { tmdbPosterUrl } from '@/lib/tmdb';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';

interface SeriesResult {
    id: number | null;
    tvdb_id: number | null;
    title: string | null;
    year: number | null;
    overview: string | null;
    title_slug: string | null;
    status: string | null;
    monitored: boolean;
    remote_poster: string | null;
}

interface MovieResult {
    id: number | null;
    tmdb_id: number | null;
    title: string | null;
    year: number | null;
    overview: string | null;
    title_slug: string | null;
    status: string | null;
    monitored: boolean;
    has_file: boolean;
    remote_poster: string | null;
}

interface RequestResult {
    id: number | null;
    media_type: string | null;
    title: string | null;
    tmdb_id: number | null;
    tvdb_id: number | null;
    status: number | null;
    overview: string | null;
    poster_path: string | null;
}

interface ServiceResult<T> {
    results: T[];
    error: string | null;
}

interface ConnectionInfo {
    url: string;
}

interface Connections {
    sonarr: ConnectionInfo | null;
    radarr: ConnectionInfo | null;
    seerr: ConnectionInfo | null;
}

interface IndexerResult {
    guid: string | null;
    title: string | null;
    tracker: string | null;
    category: string | null;
    size_bytes: number | null;
    seeders: number | null;
    leechers: number | null;
    age: string | null;
    download_url: string | null;
    info_url: string | null;
    score: number | null;
}

const props = defineProps<{
    query: string;
    scope?: 'all' | 'library' | 'requests' | 'indexers';
    connections: Connections;
    seriesResults?: ServiceResult<SeriesResult>;
    movieResults?: ServiceResult<MovieResult>;
    requestResults?: ServiceResult<RequestResult>;
    indexerResults?: ServiceResult<IndexerResult>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Media', href: dashboard().url },
            { title: 'Search', href: SearchController.index.url() },
        ],
    },
});

const query = ref(props.query);
const submitting = ref(false);
const searchInput = useTemplateRef<HTMLInputElement>('searchInput');

type Scope = 'all' | 'library' | 'requests' | 'indexers';
const scope = ref<Scope>(props.scope ?? 'all');

const SCOPES: { id: Scope; label: string }[] = [
    { id: 'all', label: 'Library + Requests' },
    { id: 'library', label: 'Library' },
    { id: 'requests', label: 'Requests' },
    { id: 'indexers', label: 'Indexers' },
];

const RECENT_KEY = 'mm:search:recent';
const RECENT_MAX = 8;
const recentQueries = ref<string[]>([]);

function loadRecent(): void {
    try {
        const raw = localStorage.getItem(RECENT_KEY);
        recentQueries.value = raw ? JSON.parse(raw) : [];
    } catch {
        recentQueries.value = [];
    }
}

function pushRecent(term: string): void {
    const t = term.trim();

    if (!t) {
        return;
    }

    const next = [t, ...recentQueries.value.filter((q) => q !== t)].slice(
        0,
        RECENT_MAX,
    );
    recentQueries.value = next;

    try {
        localStorage.setItem(RECENT_KEY, JSON.stringify(next));
    } catch {
        // localStorage full or disabled — fail silently, in-memory copy still works.
    }
}

function clearRecent(): void {
    recentQueries.value = [];

    try {
        localStorage.removeItem(RECENT_KEY);
    } catch {
        // best-effort
    }
}

onMounted(() => {
    searchInput.value?.focus();
    searchInput.value?.select();
    loadRecent();
});

function submitSearch() {
    if (submitting.value) {
        return;
    }

    pushRecent(query.value);

    router.get(
        SearchController.index.url(),
        { q: query.value, scope: scope.value },
        {
            preserveScroll: true,
            onStart: () => {
                submitting.value = true;
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

function setScope(next: Scope) {
    if (scope.value === next) {
        return;
    }

    scope.value = next;

    // Re-issue the same query so the controller can hydrate the indexer
    // results (or release them) without an extra click on the search box.
    if (query.value) {
        submitSearch();
    }
}

function clearQuery() {
    query.value = '';
    searchInput.value?.focus();
}

function sonarrSeriesUrl(slug: string | null): string | null {
    if (!slug || !props.connections.sonarr) {
        return null;
    }

    return `${props.connections.sonarr.url}/series/${slug}`;
}

function radarrMovieUrl(slug: string | null): string | null {
    if (!slug || !props.connections.radarr) {
        return null;
    }

    return `${props.connections.radarr.url}/movie/${slug}`;
}

const libraryCount = computed(
    () =>
        (props.seriesResults?.results.length ?? 0) +
        (props.movieResults?.results.length ?? 0),
);
const requestCount = computed(() => props.requestResults?.results.length ?? 0);

const showLibrary = computed(
    () => scope.value === 'all' || scope.value === 'library',
);
const showRequests = computed(
    () => scope.value === 'all' || scope.value === 'requests',
);
const showIndexers = computed(() => scope.value === 'indexers');

function formatSize(bytes: number | null): string {
    if (bytes === null || bytes === undefined) {
        return '—';
    }

    if (bytes === 0) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    const value = bytes / Math.pow(1024, i);

    return `${value.toFixed(1)} ${units[i]}`;
}

const seerrStatusKey = (status: number | null): string => {
    switch (status) {
        case 1:
            return 'pending';
        case 2:
            return 'approved';
        case 3:
            return 'declined';
        case 4:
            return 'failed';
        case 5:
            return 'available';
        default:
            return 'unknown';
    }
};
</script>

<template>
    <Head title="Search" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero -->
        <div>
            <h1 class="text-[22px] leading-tight font-semibold tracking-tight">
                Search
            </h1>
            <p class="mt-1 text-[13px] text-muted-foreground">
                One box across library and requests. Indexers opt-in.
            </p>
        </div>

        <!-- Search box -->
        <div class="rounded-xl border border-border bg-card p-3.5">
            <form
                class="flex h-11 items-center gap-2.5 rounded-md border border-border bg-bg-elev px-3"
                @submit.prevent="submitSearch"
            >
                <SearchIcon class="size-4 text-fg-subtle" />
                <input
                    ref="searchInput"
                    v-model="query"
                    type="search"
                    placeholder="Search titles, requests, releases…"
                    class="h-8 flex-1 bg-transparent text-[15px] outline-none placeholder:text-fg-subtle"
                />
                <button
                    v-if="query"
                    type="button"
                    class="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-bg-hover"
                    @click="clearQuery"
                >
                    <X class="size-3.5" />
                </button>
                <kbd
                    class="font-mono-tabular flex items-center gap-0.5 rounded border border-border bg-card px-1 py-px text-[10px] text-muted-foreground"
                >
                    <Command class="size-2.5" />K
                </kbd>
            </form>

            <div class="mt-3 flex flex-wrap items-center gap-1.5">
                <button
                    v-for="s in SCOPES"
                    :key="s.id"
                    type="button"
                    :class="
                        cn(
                            'inline-flex h-7 items-center gap-1.5 rounded-md px-2.5 text-xs font-medium transition-colors',
                            scope === s.id
                                ? 'bg-accent text-accent-foreground'
                                : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                        )
                    "
                    @click="setScope(s.id)"
                >
                    {{ s.label }}
                </button>
                <span class="ml-auto text-[11.5px] text-fg-subtle">
                    <template v-if="query">
                        <span class="font-mono-tabular">{{
                            libraryCount
                        }}</span>
                        library ·
                        <span class="font-mono-tabular">{{
                            requestCount
                        }}</span>
                        requests
                    </template>
                    <template v-else>type to search</template>
                </span>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="!query">
            <div
                v-if="recentQueries.length > 0"
                class="overflow-hidden rounded-xl border border-border bg-card"
            >
                <div
                    class="flex items-center justify-between border-b border-border px-4 py-3 text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                >
                    <span>Recent searches</span>
                    <button
                        type="button"
                        class="text-[11px] font-medium tracking-normal text-muted-foreground normal-case hover:text-foreground"
                        @click="clearRecent"
                    >
                        Clear
                    </button>
                </div>
                <div class="flex flex-col p-3">
                    <button
                        v-for="r in recentQueries"
                        :key="r"
                        type="button"
                        class="flex items-center gap-2.5 rounded-md px-2 py-2 text-left text-[13px] hover:bg-bg-hover"
                        @click="
                            query = r;
                            submitSearch();
                        "
                    >
                        <Clock class="size-3.5 text-fg-subtle" />
                        <span>{{ r }}</span>
                    </button>
                </div>
            </div>
            <div
                v-else
                class="rounded-xl border border-dashed border-border p-8 text-center text-[13px] text-muted-foreground"
            >
                Type to search across the library, requests, and indexers.
                Recent searches will show up here.
            </div>
        </div>

        <!-- Library section -->
        <section
            v-if="query && showLibrary"
            class="overflow-hidden rounded-xl border border-border bg-card"
        >
            <div
                class="flex items-center justify-between border-b border-border px-4 py-3"
            >
                <div class="flex items-center gap-2">
                    <span
                        class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                    >
                        Library
                    </span>
                    <Pill>{{ libraryCount }}</Pill>
                </div>
                <span class="text-xs text-fg-subtle"
                    >Sonarr + Radarr matches</span
                >
            </div>

            <Alert v-if="props.seriesResults?.error" variant="destructive">
                <AlertCircle class="size-4" />
                <AlertTitle>Sonarr unavailable</AlertTitle>
                <AlertDescription>{{
                    props.seriesResults.error
                }}</AlertDescription>
            </Alert>
            <Alert v-if="props.movieResults?.error" variant="destructive">
                <AlertCircle class="size-4" />
                <AlertTitle>Radarr unavailable</AlertTitle>
                <AlertDescription>{{
                    props.movieResults.error
                }}</AlertDescription>
            </Alert>

            <div
                v-if="!props.seriesResults || !props.movieResults"
                class="grid gap-4 p-4"
                style="
                    grid-template-columns: repeat(
                        auto-fill,
                        minmax(150px, 1fr)
                    );
                "
            >
                <Skeleton
                    v-for="i in 6"
                    :key="`lib-skel-${i}`"
                    class="aspect-[2/3] w-full rounded-md"
                />
            </div>
            <div
                v-else-if="libraryCount === 0"
                class="px-4 py-6 text-sm text-fg-subtle"
            >
                No matches in your library.
            </div>
            <div
                v-else
                class="grid gap-4 p-4"
                style="
                    grid-template-columns: repeat(
                        auto-fill,
                        minmax(150px, 1fr)
                    );
                "
            >
                <div
                    v-for="(series, index) in props.seriesResults.results"
                    :key="`series-${series.id ?? series.tvdb_id ?? index}`"
                    class="flex flex-col gap-2"
                >
                    <div class="relative">
                        <Poster
                            :hint="
                                (series.title ?? 'tv')
                                    .toLowerCase()
                                    .slice(0, 12)
                            "
                            :src="series.remote_poster"
                            size="full"
                        />
                        <Pill
                            class="absolute top-2 left-2 border-transparent bg-black/55 text-white"
                        >
                            <SvcChip id="sonarr" label="TV" />
                        </Pill>
                        <a
                            v-if="sonarrSeriesUrl(series.title_slug)"
                            :href="sonarrSeriesUrl(series.title_slug)!"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="absolute top-2 right-2 inline-flex size-6 items-center justify-center rounded-md bg-black/55 text-white"
                        >
                            <ExternalLink class="size-3" />
                        </a>
                    </div>
                    <div>
                        <div class="text-[12.5px] leading-tight font-medium">
                            {{ series.title ?? 'Unknown' }}
                        </div>
                        <div
                            class="font-mono-tabular mt-0.5 text-[10.5px] text-fg-subtle"
                        >
                            {{ series.year ?? '—' }} ·
                            {{ series.status ?? 'unknown' }}
                        </div>
                    </div>
                </div>
                <div
                    v-for="(movie, index) in props.movieResults.results"
                    :key="`movie-${movie.id ?? movie.tmdb_id ?? index}`"
                    class="flex flex-col gap-2"
                >
                    <div class="relative">
                        <Poster
                            :hint="
                                (movie.title ?? 'film')
                                    .toLowerCase()
                                    .slice(0, 12)
                            "
                            :src="movie.remote_poster"
                            size="full"
                        />
                        <Pill
                            class="absolute top-2 left-2 border-transparent bg-black/55 text-white"
                        >
                            <SvcChip id="radarr" label="Movie" />
                        </Pill>
                        <a
                            v-if="radarrMovieUrl(movie.title_slug)"
                            :href="radarrMovieUrl(movie.title_slug)!"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="absolute top-2 right-2 inline-flex size-6 items-center justify-center rounded-md bg-black/55 text-white"
                        >
                            <ExternalLink class="size-3" />
                        </a>
                    </div>
                    <div>
                        <div class="text-[12.5px] leading-tight font-medium">
                            {{ movie.title ?? 'Unknown' }}
                        </div>
                        <div
                            class="font-mono-tabular mt-0.5 text-[10.5px] text-fg-subtle"
                        >
                            {{ movie.year ?? '—' }} ·
                            {{ movie.has_file ? 'on disk' : 'missing' }}
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Requests section -->
        <section
            v-if="query && showRequests"
            class="overflow-hidden rounded-xl border border-border bg-card"
        >
            <div
                class="flex items-center justify-between border-b border-border px-4 py-3"
            >
                <div class="flex items-center gap-2">
                    <SvcChip id="seerr" />
                    <span
                        class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                    >
                        Requests
                    </span>
                    <Pill>{{ requestCount }}</Pill>
                </div>
            </div>

            <Alert v-if="props.requestResults?.error" variant="destructive">
                <AlertCircle class="size-4" />
                <AlertTitle>Seerr unavailable</AlertTitle>
                <AlertDescription>{{
                    props.requestResults.error
                }}</AlertDescription>
            </Alert>

            <div v-if="!props.requestResults" class="space-y-2 p-4">
                <Skeleton
                    v-for="i in 3"
                    :key="`req-skel-${i}`"
                    class="h-14 w-full rounded-md"
                />
            </div>
            <div
                v-else-if="requestCount === 0"
                class="px-4 py-6 text-sm text-fg-subtle"
            >
                No matching requests.
            </div>
            <div v-else>
                <div
                    v-for="(request, i) in props.requestResults.results"
                    :key="`req-${request.id ?? i}`"
                    :class="[
                        'flex items-center gap-3.5 px-4 py-3',
                        i > 0 && 'border-t border-border',
                    ]"
                >
                    <Poster
                        :hint="
                            (request.title ?? 'media')
                                .toLowerCase()
                                .slice(0, 12)
                        "
                        :src="tmdbPosterUrl(request.poster_path)"
                        size="sm"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="text-[13px] font-medium">
                            {{ request.title ?? 'Unknown' }}
                        </div>
                        <div class="text-[11.5px] text-muted-foreground">
                            {{ request.media_type ?? 'unknown' }}
                        </div>
                    </div>
                    <StatusPill :status="seerrStatusKey(request.status)" />
                </div>
            </div>
        </section>

        <!-- Indexers section (opt-in) -->
        <section
            v-if="query && showIndexers"
            class="overflow-hidden rounded-xl border border-border bg-card"
        >
            <div
                class="flex items-center justify-between border-b border-border px-4 py-3"
            >
                <div class="flex items-center gap-2">
                    <SvcChip id="prowlarr" />
                    <span
                        class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                    >
                        Indexer releases
                    </span>
                    <Pill v-if="indexerResults">{{
                        indexerResults.results.length
                    }}</Pill>
                </div>
                <span class="text-xs text-fg-subtle"
                    >indexer hits excluded from "All" by default</span
                >
            </div>

            <Alert v-if="indexerResults?.error" variant="destructive">
                <AlertCircle class="size-4" />
                <AlertTitle>Prowlarr unavailable</AlertTitle>
                <AlertDescription>{{ indexerResults.error }}</AlertDescription>
            </Alert>

            <div v-if="!indexerResults" class="space-y-2 p-4">
                <Skeleton
                    v-for="i in 6"
                    :key="`idx-skel-${i}`"
                    class="h-9 w-full rounded-md"
                />
            </div>
            <div
                v-else-if="indexerResults.results.length === 0"
                class="px-4 py-6 text-sm text-fg-subtle"
            >
                No indexer hits.
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th
                                v-for="h in [
                                    'Release',
                                    'Tracker',
                                    'Cat',
                                    'Size',
                                    'S / L',
                                    'Age',
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
                            v-for="(hit, i) in indexerResults.results"
                            :key="hit.guid ?? i"
                            class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                        >
                            <td class="px-3 py-2.5">
                                <a
                                    v-if="hit.info_url"
                                    :href="hit.info_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="font-mono-tabular block max-w-[520px] truncate text-[12px] font-medium hover:text-accent"
                                >
                                    {{ hit.title }}
                                </a>
                                <span
                                    v-else
                                    class="font-mono-tabular block max-w-[520px] truncate text-[12px] font-medium"
                                    >{{ hit.title }}</span
                                >
                            </td>
                            <td class="px-3 py-2.5">
                                <Pill>{{ hit.tracker ?? '—' }}</Pill>
                            </td>
                            <td
                                class="font-mono-tabular px-3 py-2.5 text-[11.5px] text-muted-foreground"
                            >
                                {{ hit.category ?? '—' }}
                            </td>
                            <td
                                class="font-mono-tabular px-3 py-2.5 text-[12px]"
                            >
                                {{ formatSize(hit.size_bytes) }}
                            </td>
                            <td
                                class="font-mono-tabular px-3 py-2.5 text-[12px]"
                            >
                                <span class="text-success">{{
                                    hit.seeders ?? '—'
                                }}</span>
                                <span class="text-fg-subtle"
                                    >/ {{ hit.leechers ?? '—' }}</span
                                >
                            </td>
                            <td
                                class="font-mono-tabular px-3 py-2.5 text-[11.5px] text-fg-subtle"
                            >
                                {{ hit.age ?? '—' }}
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <a
                                    v-if="hit.download_url"
                                    :href="hit.download_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex h-6 items-center gap-1 rounded-md border border-border px-2 text-[11.5px] hover:bg-bg-hover"
                                >
                                    Grab
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
