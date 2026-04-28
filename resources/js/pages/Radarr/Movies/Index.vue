<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink, Filter, Plus, RefreshCcw, Search } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import MovieController from '@/actions/App/Http/Controllers/Media/MovieController';
import { Pill, Poster, SvcChip } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useRealtimeReload } from '@/composables/useRealtimeReload';
import { dashboard } from '@/routes';

interface QualityProfile {
    id: number;
    name: string;
}

interface MovieImage {
    coverType: string;
    remoteUrl?: string;
    url?: string;
}

interface Movie {
    id: number;
    title: string;
    title_slug: string | null;
    year: number | null;
    status: string | null;
    monitored: boolean;
    has_file: boolean;
    quality_profile_id: number | null;
    size_on_disk: number;
    images: MovieImage[];
}

const props = defineProps<{
    connection: { url: string };
    movies?: Movie[];
    qualityProfiles?: QualityProfile[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Media', href: dashboard().url },
            { title: 'Movies', href: MovieController.index.url() },
        ],
    },
});

const { subscribe: subscribeReload } = useRealtimeReload<{
    service_type: string | null;
}>({
    channel: 'dashboard',
    event: 'WebhookReceived',
    only: ['movies'],
    filter: (event) => event.service_type === 'radarr',
});

onMounted(subscribeReload);

const query = ref('');
const visible = computed<Movie[]>(() => {
    if (!props.movies) {
return [];
}

    if (!query.value) {
return props.movies;
}

    const q = query.value.toLowerCase();

    return props.movies.filter((m) => m.title.toLowerCase().includes(q));
});

const totalSize = computed(() => {
    const sum = (props.movies ?? []).reduce(
        (acc, m) => acc + (m.size_on_disk ?? 0),
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
return `${tb.toFixed(2)} TB`;
}

    const gb = bytes / 1024 ** 3;

    if (gb >= 1) {
return `${gb.toFixed(1)} GB`;
}

    const mb = bytes / 1024 ** 2;

    return `${mb.toFixed(0)} MB`;
}

function qualityName(id: number | null): string {
    if (id === null) {
return '—';
}

    return props.qualityProfiles?.find((p) => p.id === id)?.name ?? '—';
}

function is4k(movie: Movie): boolean {
    const profile = qualityName(movie.quality_profile_id).toLowerCase();

    return profile.includes('2160') || profile.includes('uhd') || profile.includes('4k');
}
</script>

<template>
    <Head title="Movies" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero -->
        <div class="flex items-end justify-between gap-3">
            <div>
                <div class="mb-1.5 flex items-center gap-2">
                    <SvcChip id="radarr" />
                    <span class="text-fg-subtle">/</span>
                    <span class="text-[13px] text-muted-foreground"
                        >Movies</span
                    >
                </div>
                <h1 class="text-[22px] font-semibold tracking-tight">
                    Movies
                </h1>
                <p
                    v-if="movies"
                    class="mt-1 text-[13px] text-muted-foreground"
                >
                    {{ movies.length }} titles ·
                    <span class="font-mono-tabular">{{ totalSize }}</span> on
                    disk
                </p>
                <Skeleton v-else class="mt-1 h-4 w-48" />
            </div>
            <div class="flex items-center gap-2">
                <Button variant="outline" size="sm" class="h-7 gap-1.5 text-xs">
                    <RefreshCcw class="size-3.5" />Sync
                </Button>
                <Link :href="MovieController.create.url()">
                    <Button size="sm" class="h-7 gap-1.5 text-xs">
                        <Plus class="size-3.5" />Add movie
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
                    :placeholder="`Search ${movies?.length ?? 0} movies…`"
                    class="flex-1 bg-transparent text-[13px] outline-none placeholder:text-fg-subtle"
                />
            </div>
            <Button variant="outline" size="sm" class="h-7 gap-1.5 text-xs">
                <Filter class="size-3.5" />Quality
            </Button>
            <Button variant="outline" size="sm" class="h-7 gap-1.5 text-xs">
                <Filter class="size-3.5" />Year
            </Button>
            <Button variant="outline" size="sm" class="h-7 gap-1.5 text-xs">
                <Filter class="size-3.5" />Studio
            </Button>
        </div>

        <!-- Grid -->
        <div
            v-if="movies"
            class="grid gap-[18px]"
            style="grid-template-columns: repeat(auto-fill, minmax(150px, 1fr))"
        >
            <Link
                v-for="movie in visible"
                :key="movie.id"
                :href="MovieController.show.url(movie.id)"
                class="group flex flex-col gap-2"
            >
                <div class="relative">
                    <Poster
                        :hint="movie.title.toLowerCase().slice(0, 12)"
                        size="full"
                    />
                    <Pill
                        v-if="is4k(movie)"
                        class="absolute top-2 left-2 border-transparent bg-black/55 text-white"
                    >
                        4K
                    </Pill>
                    <Pill
                        v-if="!movie.has_file"
                        class="absolute bottom-2 left-2 border-transparent bg-black/55 text-white/70"
                    >
                        missing
                    </Pill>
                    <a
                        v-if="movie.title_slug"
                        :href="`${connection.url}/movie/${movie.title_slug}`"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="absolute top-2 right-2 inline-flex size-6 items-center justify-center rounded-md bg-black/55 text-white opacity-0 transition-opacity group-hover:opacity-100"
                        @click.stop
                    >
                        <ExternalLink class="size-3" />
                    </a>
                </div>
                <div>
                    <div
                        class="text-[13px] leading-tight font-medium text-pretty group-hover:text-accent"
                    >
                        {{ movie.title }}
                    </div>
                    <div
                        class="font-mono-tabular mt-0.5 flex justify-between text-[10.5px] text-fg-subtle"
                    >
                        <span>{{ movie.year ?? '—' }}</span>
                        <span>{{ formatSize(movie.size_on_disk) }}</span>
                    </div>
                </div>
            </Link>
            <div
                v-if="visible.length === 0"
                class="col-span-full py-8 text-center text-sm text-fg-subtle"
            >
                No movies match.
            </div>
        </div>

        <!-- Skeleton -->
        <div
            v-else
            class="grid gap-[18px]"
            style="grid-template-columns: repeat(auto-fill, minmax(150px, 1fr))"
        >
            <div v-for="n in 8" :key="`skel-${n}`" class="flex flex-col gap-2">
                <Skeleton class="aspect-[2/3] w-full rounded-md" />
                <Skeleton class="h-3 w-3/4" />
                <Skeleton class="h-2 w-1/2" />
            </div>
        </div>
    </div>
</template>
