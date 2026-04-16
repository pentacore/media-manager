<script setup lang="ts">
import { computed, onMounted, ref, useTemplateRef } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { AlertTriangle, Film, ImageOff, Inbox, Search as SearchIcon, Tv } from 'lucide-vue-next'
import SearchController from '@/actions/App/Http/Controllers/Media/SearchController'
import { dashboard } from '@/routes'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'

interface SeriesResult {
    tvdb_id: number | null
    title: string | null
    year: number | null
    overview: string | null
    remote_poster: string | null
}

interface MovieResult {
    tmdb_id: number | null
    title: string | null
    year: number | null
    overview: string | null
    remote_poster: string | null
}

interface RequestResult {
    id: number | null
    media_type: string | null
    title: string | null
    overview: string | null
    poster_path: string | null
}

const props = defineProps<{
    query: string
    results: {
        series: SeriesResult[]
        movies: MovieResult[]
        requests: RequestResult[]
    }
    errors: string[]
}>()

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Search', href: SearchController.index.url() },
        ],
    },
})

const query = ref(props.query)
const searchInput = useTemplateRef<HTMLInputElement>('searchInput')

const hasResults = computed(
    () =>
        props.results.series.length > 0 ||
        props.results.movies.length > 0 ||
        props.results.requests.length > 0,
)

const showNoResults = computed(
    () => props.query !== '' && !hasResults.value && props.errors.length === 0,
)

function submitSearch() {
    router.get(
        SearchController.index.url(),
        { q: query.value },
        { preserveState: true, preserveScroll: true },
    )
}

function serviceLabel(service: string): string {
    return service.charAt(0).toUpperCase() + service.slice(1)
}

onMounted(() => {
    searchInput.value?.focus()
})
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
                    placeholder="Search series, movies, and requests..."
                    class="flex-1"
                />
                <Button type="submit">
                    <SearchIcon class="size-4" />
                    Search
                </Button>
            </form>
        </div>

        <Alert v-if="errors.length > 0" variant="destructive">
            <AlertTriangle class="size-4" />
            <AlertTitle>Some services are unavailable</AlertTitle>
            <AlertDescription>
                Results may be incomplete. Unavailable:
                <span class="font-medium">{{ errors.map(serviceLabel).join(', ') }}</span>
            </AlertDescription>
        </Alert>

        <div
            v-if="query === ''"
            class="flex flex-col items-center justify-center py-20 text-center"
        >
            <SearchIcon class="mb-4 size-16 text-muted-foreground/50" />
            <p class="text-lg font-medium">Search across Sonarr, Radarr, and Seerr</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Type above to find series, movies, and existing media requests.
            </p>
        </div>

        <div
            v-else-if="showNoResults"
            class="flex flex-col items-center justify-center py-20 text-center"
        >
            <SearchIcon class="mb-4 size-16 text-muted-foreground/50" />
            <p class="text-lg font-medium">No results found for "{{ query }}".</p>
        </div>

        <!-- Series -->
        <section v-if="results.series.length > 0" class="space-y-3">
            <div class="flex items-center gap-2">
                <Tv class="size-5 text-muted-foreground" />
                <h3 class="text-lg font-semibold">Series (Sonarr)</h3>
                <Badge variant="secondary">{{ results.series.length }}</Badge>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Card v-for="(series, index) in results.series" :key="`series-${series.tvdb_id ?? index}`">
                    <CardHeader>
                        <div class="flex gap-3">
                            <div class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
                                <img
                                    v-if="series.remote_poster"
                                    :src="series.remote_poster"
                                    :alt="series.title ?? 'Poster'"
                                    class="size-full object-cover"
                                />
                                <ImageOff v-else class="size-6 text-muted-foreground/50" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <CardTitle class="truncate text-base">{{ series.title ?? 'Unknown' }}</CardTitle>
                                <p v-if="series.year" class="text-sm text-muted-foreground">{{ series.year }}</p>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="line-clamp-2 text-sm text-muted-foreground">
                            {{ series.overview ?? 'No overview available.' }}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <!-- Movies -->
        <section v-if="results.movies.length > 0" class="space-y-3">
            <div class="flex items-center gap-2">
                <Film class="size-5 text-muted-foreground" />
                <h3 class="text-lg font-semibold">Movies (Radarr)</h3>
                <Badge variant="secondary">{{ results.movies.length }}</Badge>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Card v-for="(movie, index) in results.movies" :key="`movie-${movie.tmdb_id ?? index}`">
                    <CardHeader>
                        <div class="flex gap-3">
                            <div class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
                                <img
                                    v-if="movie.remote_poster"
                                    :src="movie.remote_poster"
                                    :alt="movie.title ?? 'Poster'"
                                    class="size-full object-cover"
                                />
                                <ImageOff v-else class="size-6 text-muted-foreground/50" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <CardTitle class="truncate text-base">{{ movie.title ?? 'Unknown' }}</CardTitle>
                                <p v-if="movie.year" class="text-sm text-muted-foreground">{{ movie.year }}</p>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="line-clamp-2 text-sm text-muted-foreground">
                            {{ movie.overview ?? 'No overview available.' }}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <!-- Requests -->
        <section v-if="results.requests.length > 0" class="space-y-3">
            <div class="flex items-center gap-2">
                <Inbox class="size-5 text-muted-foreground" />
                <h3 class="text-lg font-semibold">Requests (Seerr)</h3>
                <Badge variant="secondary">{{ results.requests.length }}</Badge>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <Card v-for="(request, index) in results.requests" :key="`request-${request.id ?? index}`">
                    <CardHeader>
                        <div class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
                            <Inbox v-if="!request.poster_path" class="size-6 text-muted-foreground/50" />
                            <ImageOff v-else class="size-6 text-muted-foreground/50" />
                        </div>
                        <CardTitle class="truncate text-base">{{ request.title ?? 'Unknown' }}</CardTitle>
                        <Badge v-if="request.media_type" variant="outline" class="w-fit">
                            {{ request.media_type }}
                        </Badge>
                    </CardHeader>
                    <CardContent>
                        <p class="line-clamp-2 text-sm text-muted-foreground">
                            {{ request.overview ?? 'No overview available.' }}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>
    </div>
</template>
