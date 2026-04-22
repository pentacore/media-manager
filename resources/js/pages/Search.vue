<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    ExternalLink,
    Film,
    ImageOff,
    Inbox,
    Search as SearchIcon,
    Tv,
} from 'lucide-vue-next';
import { onMounted, ref, useTemplateRef } from 'vue';
import SearchController from '@/actions/App/Http/Controllers/Media/SearchController';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
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

const props = defineProps<{
    query: string;
    connections: Connections;
    seriesResults?: ServiceResult<SeriesResult>;
    movieResults?: ServiceResult<MovieResult>;
    requestResults?: ServiceResult<RequestResult>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Search', href: SearchController.index.url() },
        ],
    },
});

const query = ref(props.query);
const submitting = ref(false);
const searchInput = useTemplateRef<HTMLInputElement>('searchInput');

onMounted(() => {
    searchInput.value?.focus();
    searchInput.value?.select();
});

function submitSearch() {
    if (submitting.value) {
        return;
    }

    router.get(
        SearchController.index.url(),
        { q: query.value },
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
</script>

<template>
    <Head title="Search" />

    <div class="space-y-6 p-6">
        <div class="mx-auto w-full max-w-2xl">
            <form class="flex gap-2" @submit.prevent="submitSearch">
                <Input
                    ref="searchInput"
                    v-model="query"
                    type="search"
                    placeholder="Search your library for series, movies, and requests..."
                    class="flex-1"
                    :disabled="submitting"
                />
                <Button type="submit" :disabled="submitting">
                    <Spinner v-if="submitting" class="size-4" />
                    <SearchIcon v-else class="size-4" />
                    Search
                </Button>
            </form>
        </div>

        <div
            v-if="query === ''"
            class="flex flex-col items-center justify-center py-20 text-center"
        >
            <SearchIcon class="mb-4 size-16 text-muted-foreground/50" />
            <p class="text-lg font-medium">
                Search your library across Sonarr, Radarr, and Seerr
            </p>
            <p class="mt-1 text-sm text-muted-foreground">
                Type above to find series, movies, and existing media requests
                already tracked.
            </p>
        </div>

        <template v-else>
            <TooltipProvider :delay-duration="200">
                <!-- Series -->
                <section class="space-y-3">
                    <div class="flex items-center gap-2">
                        <Tv class="size-5 text-muted-foreground" />
                        <h3 class="text-lg font-semibold">Series (Sonarr)</h3>
                        <Badge v-if="props.seriesResults" variant="secondary">
                            {{ props.seriesResults.results.length }}
                        </Badge>
                    </div>
                    <Alert
                        v-if="props.seriesResults?.error"
                        variant="destructive"
                    >
                        <AlertCircle class="size-4" />
                        <AlertTitle>Sonarr unavailable</AlertTitle>
                        <AlertDescription>{{
                            props.seriesResults.error
                        }}</AlertDescription>
                    </Alert>
                    <div
                        v-else-if="!props.seriesResults"
                        class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3"
                    >
                        <Skeleton
                            v-for="i in 3"
                            :key="`series-skel-${i}`"
                            class="h-32 w-full"
                        />
                    </div>
                    <p
                        v-else-if="props.seriesResults.results.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No matches in your Sonarr library.
                    </p>
                    <div
                        v-else
                        class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3"
                    >
                        <Card
                            v-for="(series, index) in props.seriesResults
                                .results"
                            :key="`series-${series.id ?? series.tvdb_id ?? index}`"
                        >
                            <CardHeader>
                                <div class="flex gap-3">
                                    <div
                                        class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded bg-muted"
                                    >
                                        <Tv
                                            class="size-6 text-muted-foreground/50"
                                        />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="flex items-start justify-between gap-2"
                                        >
                                            <CardTitle
                                                class="truncate text-base"
                                                >{{
                                                    series.title ?? 'Unknown'
                                                }}</CardTitle
                                            >
                                            <Tooltip
                                                v-if="
                                                    sonarrSeriesUrl(
                                                        series.title_slug,
                                                    )
                                                "
                                            >
                                                <TooltipTrigger as-child>
                                                    <a
                                                        :href="
                                                            sonarrSeriesUrl(
                                                                series.title_slug,
                                                            ) ?? undefined
                                                        "
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                    >
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            class="size-6"
                                                        >
                                                            <ExternalLink
                                                                class="size-3.5"
                                                            />
                                                            <span
                                                                class="sr-only"
                                                                >Open in
                                                                Sonarr</span
                                                            >
                                                        </Button>
                                                    </a>
                                                </TooltipTrigger>
                                                <TooltipContent
                                                    >Open in Sonarr</TooltipContent
                                                >
                                            </Tooltip>
                                        </div>
                                        <div
                                            class="mt-1 flex flex-wrap items-center gap-2"
                                        >
                                            <p
                                                v-if="series.year"
                                                class="text-sm text-muted-foreground"
                                            >
                                                {{ series.year }}
                                            </p>
                                            <Badge
                                                v-if="series.monitored"
                                                variant="outline"
                                                class="text-xs"
                                                >Monitored</Badge
                                            >
                                            <Badge
                                                v-if="series.status"
                                                variant="secondary"
                                                class="text-xs"
                                                >{{ series.status }}</Badge
                                            >
                                        </div>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <p
                                    class="line-clamp-2 text-sm text-muted-foreground"
                                >
                                    {{
                                        series.overview ??
                                        'No overview available.'
                                    }}
                                </p>
                            </CardContent>
                        </Card>
                    </div>
                </section>

                <!-- Movies -->
                <section class="space-y-3">
                    <div class="flex items-center gap-2">
                        <Film class="size-5 text-muted-foreground" />
                        <h3 class="text-lg font-semibold">Movies (Radarr)</h3>
                        <Badge v-if="props.movieResults" variant="secondary">
                            {{ props.movieResults.results.length }}
                        </Badge>
                    </div>
                    <Alert
                        v-if="props.movieResults?.error"
                        variant="destructive"
                    >
                        <AlertCircle class="size-4" />
                        <AlertTitle>Radarr unavailable</AlertTitle>
                        <AlertDescription>{{
                            props.movieResults.error
                        }}</AlertDescription>
                    </Alert>
                    <div
                        v-else-if="!props.movieResults"
                        class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3"
                    >
                        <Skeleton
                            v-for="i in 3"
                            :key="`movie-skel-${i}`"
                            class="h-32 w-full"
                        />
                    </div>
                    <p
                        v-else-if="props.movieResults.results.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No matches in your Radarr library.
                    </p>
                    <div
                        v-else
                        class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3"
                    >
                        <Card
                            v-for="(movie, index) in props.movieResults.results"
                            :key="`movie-${movie.id ?? movie.tmdb_id ?? index}`"
                        >
                            <CardHeader>
                                <div class="flex gap-3">
                                    <div
                                        class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded bg-muted"
                                    >
                                        <Film
                                            class="size-6 text-muted-foreground/50"
                                        />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="flex items-start justify-between gap-2"
                                        >
                                            <CardTitle
                                                class="truncate text-base"
                                                >{{
                                                    movie.title ?? 'Unknown'
                                                }}</CardTitle
                                            >
                                            <Tooltip
                                                v-if="
                                                    radarrMovieUrl(
                                                        movie.title_slug,
                                                    )
                                                "
                                            >
                                                <TooltipTrigger as-child>
                                                    <a
                                                        :href="
                                                            radarrMovieUrl(
                                                                movie.title_slug,
                                                            ) ?? undefined
                                                        "
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                    >
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            class="size-6"
                                                        >
                                                            <ExternalLink
                                                                class="size-3.5"
                                                            />
                                                            <span
                                                                class="sr-only"
                                                                >Open in
                                                                Radarr</span
                                                            >
                                                        </Button>
                                                    </a>
                                                </TooltipTrigger>
                                                <TooltipContent
                                                    >Open in Radarr</TooltipContent
                                                >
                                            </Tooltip>
                                        </div>
                                        <div
                                            class="mt-1 flex flex-wrap items-center gap-2"
                                        >
                                            <p
                                                v-if="movie.year"
                                                class="text-sm text-muted-foreground"
                                            >
                                                {{ movie.year }}
                                            </p>
                                            <Badge
                                                v-if="movie.monitored"
                                                variant="outline"
                                                class="text-xs"
                                                >Monitored</Badge
                                            >
                                            <Badge
                                                v-if="movie.has_file"
                                                variant="default"
                                                class="text-xs"
                                                >Downloaded</Badge
                                            >
                                        </div>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <p
                                    class="line-clamp-2 text-sm text-muted-foreground"
                                >
                                    {{
                                        movie.overview ??
                                        'No overview available.'
                                    }}
                                </p>
                            </CardContent>
                        </Card>
                    </div>
                </section>

                <!-- Requests -->
                <section class="space-y-3">
                    <div class="flex items-center gap-2">
                        <Inbox class="size-5 text-muted-foreground" />
                        <h3 class="text-lg font-semibold">Requests (Seerr)</h3>
                        <Badge v-if="props.requestResults" variant="secondary">
                            {{ props.requestResults.results.length }}
                        </Badge>
                    </div>
                    <Alert
                        v-if="props.requestResults?.error"
                        variant="destructive"
                    >
                        <AlertCircle class="size-4" />
                        <AlertTitle>Seerr unavailable</AlertTitle>
                        <AlertDescription>{{
                            props.requestResults.error
                        }}</AlertDescription>
                    </Alert>
                    <div
                        v-else-if="!props.requestResults"
                        class="grid grid-cols-1 gap-4 md:grid-cols-4"
                    >
                        <Skeleton
                            v-for="i in 4"
                            :key="`request-skel-${i}`"
                            class="h-32 w-full"
                        />
                    </div>
                    <p
                        v-else-if="props.requestResults.results.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No matching existing requests in Seerr.
                    </p>
                    <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-4">
                        <Card
                            v-for="(request, index) in props.requestResults
                                .results"
                            :key="`request-${request.id ?? index}`"
                        >
                            <CardHeader>
                                <div
                                    class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded bg-muted"
                                >
                                    <Inbox
                                        v-if="!request.poster_path"
                                        class="size-6 text-muted-foreground/50"
                                    />
                                    <ImageOff
                                        v-else
                                        class="size-6 text-muted-foreground/50"
                                    />
                                </div>
                                <CardTitle class="truncate text-base">{{
                                    request.title ?? 'Unknown'
                                }}</CardTitle>
                                <Badge
                                    v-if="request.media_type"
                                    variant="outline"
                                    class="w-fit"
                                >
                                    {{ request.media_type }}
                                </Badge>
                            </CardHeader>
                            <CardContent>
                                <p
                                    class="line-clamp-2 text-sm text-muted-foreground"
                                >
                                    {{
                                        request.overview ??
                                        'No overview available.'
                                    }}
                                </p>
                            </CardContent>
                        </Card>
                    </div>
                </section>
            </TooltipProvider>
        </template>
    </div>
</template>
