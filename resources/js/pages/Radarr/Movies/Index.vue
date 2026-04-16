<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import MovieController from '@/actions/App/Http/Controllers/Media/MovieController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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
    year: number | null;
    status: string | null;
    monitored: boolean;
    has_file: boolean;
    quality_profile_id: number | null;
    size_on_disk: number;
    images: MovieImage[];
}

const props = defineProps<{
    movies: Movie[];
    qualityProfiles: QualityProfile[];
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

    const profile = props.qualityProfiles.find((p) => p.id === id);

    return profile?.name ?? '-';
}

function monitoredCount(): number {
    return props.movies.filter((m) => m.monitored).length;
}
</script>

<template>
    <Head title="Movies" />

    <div class="space-y-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold tracking-tight">Movies</h2>
                <p class="text-muted-foreground">
                    {{ movies.length }} movies, {{ monitoredCount() }} monitored
                </p>
            </div>
            <Link :href="MovieController.create.url()">
                <Button>
                    <Plus class="mr-2 size-4" />
                    Add Movie
                </Button>
            </Link>
        </div>

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
                <TableRow v-for="movie in movies" :key="movie.id">
                    <TableCell class="font-medium">{{ movie.title }}</TableCell>
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
                            :variant="movie.has_file ? 'default' : 'secondary'"
                        >
                            {{ movie.has_file ? 'Yes' : 'No' }}
                        </Badge>
                    </TableCell>
                    <TableCell class="text-muted-foreground">
                        {{ formatSize(movie.size_on_disk) }}
                    </TableCell>
                    <TableCell class="text-right">
                        <Link :href="MovieController.show.url(movie.id)">
                            <Button variant="ghost" size="sm">View</Button>
                        </Link>
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
            </TableBody>
        </Table>
    </div>
</template>
