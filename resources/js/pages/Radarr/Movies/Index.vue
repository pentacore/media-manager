<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink, Plus } from 'lucide-vue-next';
import MovieController from '@/actions/App/Http/Controllers/Media/MovieController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
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
            { title: 'Dashboard', href: dashboard() },
            { title: 'Movies', href: MovieController.index.url() },
        ],
    },
});

function formatSize(bytes: number): string {
    if (!bytes || bytes <= 0) {
        return '0 GB';
    }

    const gb = bytes / (1024 * 1024 * 1024);

    if (gb < 1) {
        const mb = bytes / (1024 * 1024);

        return `${mb.toFixed(0)} MB`;
    }

    return `${gb.toFixed(2)} GB`;
}

function qualityName(id: number | null): string {
    if (id === null) {
        return '-';
    }

    const profile = props.qualityProfiles?.find((p) => p.id === id);

    return profile?.name ?? '-';
}

function monitoredCount(): number {
    return (props.movies ?? []).filter((m) => m.monitored).length;
}
</script>

<template>
    <Head title="Movies" />

    <div class="space-y-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold tracking-tight">Movies</h2>
                <p v-if="movies" class="text-muted-foreground">
                    {{ movies.length }} movies, {{ monitoredCount() }} monitored
                </p>
                <Skeleton v-else class="mt-1 h-4 w-48" />
            </div>
            <Link :href="MovieController.create.url()">
                <Button>
                    <Plus class="mr-2 size-4" />
                    Add Movie
                </Button>
            </Link>
        </div>

        <TooltipProvider :delay-duration="200">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Title</TableHead>
                        <TableHead>Year</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Quality</TableHead>
                        <TableHead>Has File</TableHead>
                        <TableHead>Size</TableHead>
                        <TableHead class="text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-if="movies">
                        <TableRow v-for="movie in movies" :key="movie.id">
                            <TableCell class="font-medium">{{
                                movie.title
                            }}</TableCell>
                            <TableCell class="text-muted-foreground">{{
                                movie.year ?? '-'
                            }}</TableCell>
                            <TableCell>
                                <Badge variant="outline">{{
                                    movie.status ?? 'unknown'
                                }}</Badge>
                            </TableCell>
                            <TableCell class="text-muted-foreground">
                                {{ qualityName(movie.quality_profile_id) }}
                            </TableCell>
                            <TableCell>
                                <Badge
                                    :variant="
                                        movie.has_file ? 'default' : 'secondary'
                                    "
                                >
                                    {{ movie.has_file ? 'Yes' : 'No' }}
                                </Badge>
                            </TableCell>
                            <TableCell class="text-muted-foreground">
                                {{ formatSize(movie.size_on_disk) }}
                            </TableCell>
                            <TableCell class="text-right">
                                <div class="inline-flex items-center gap-1">
                                    <Tooltip v-if="movie.title_slug">
                                        <TooltipTrigger as-child>
                                            <a
                                                :href="`${connection.url}/movie/${movie.title_slug}`"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                >
                                                    <ExternalLink
                                                        class="size-4"
                                                    />
                                                    <span class="sr-only"
                                                        >Open in Radarr</span
                                                    >
                                                </Button>
                                            </a>
                                        </TooltipTrigger>
                                        <TooltipContent
                                            >Open in Radarr</TooltipContent
                                        >
                                    </Tooltip>
                                    <Link
                                        :href="
                                            MovieController.show.url(movie.id)
                                        "
                                    >
                                        <Button variant="ghost" size="sm"
                                            >View</Button
                                        >
                                    </Link>
                                </div>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="movies.length === 0">
                            <TableCell
                                :colspan="7"
                                class="py-8 text-center text-muted-foreground"
                            >
                                No movies yet. Add one to get started.
                            </TableCell>
                        </TableRow>
                    </template>
                    <template v-else>
                        <TableRow v-for="i in 8" :key="`skeleton-${i}`">
                            <TableCell>
                                <Skeleton class="h-4 w-48" />
                            </TableCell>
                            <TableCell>
                                <Skeleton class="h-4 w-12" />
                            </TableCell>
                            <TableCell>
                                <Skeleton class="h-5 w-20 rounded-full" />
                            </TableCell>
                            <TableCell>
                                <Skeleton class="h-4 w-24" />
                            </TableCell>
                            <TableCell>
                                <Skeleton class="h-5 w-10 rounded-full" />
                            </TableCell>
                            <TableCell>
                                <Skeleton class="h-4 w-16" />
                            </TableCell>
                            <TableCell class="text-right">
                                <Skeleton class="ml-auto h-8 w-16" />
                            </TableCell>
                        </TableRow>
                    </template>
                </TableBody>
            </Table>
        </TooltipProvider>
    </div>
</template>
