<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Calendar,
    Clock,
    ExternalLink,
    Film,
    HardDrive,
    Trash2,
} from 'lucide-vue-next';
import { ref } from 'vue';
import MovieController from '@/actions/App/Http/Controllers/Media/MovieController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { dashboard } from '@/routes';

interface MovieImage {
    coverType: string;
    remoteUrl?: string;
    url?: string;
}

interface MovieFile {
    quality: string | null;
    size: number;
    relative_path: string | null;
}

interface MovieDetail {
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
    overview: string | null;
    runtime: number | null;
    studio: string | null;
    root_folder_path: string | null;
    movie_file: MovieFile | null;
}

const props = defineProps<{
    connection: { url: string };
    movie: MovieDetail;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Movies', href: MovieController.index.url() },
        ],
    },
});

const deleteDialogOpen = ref(false);
const deleteFiles = ref(false);

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

function posterUrl(): string | null {
    const poster = props.movie.images.find(
        (image) => image.coverType === 'poster',
    );

    if (!poster) {
        return null;
    }

    return poster.remoteUrl ?? poster.url ?? null;
}

function confirmDelete() {
    router.delete(MovieController.destroy.url(props.movie.id), {
        data: { delete_files: deleteFiles.value },
        preserveScroll: true,
        onSuccess: () => {
            deleteDialogOpen.value = false;
        },
    });
}
</script>

<template>
    <Head :title="movie.title" />

    <div class="space-y-6 p-6">
        <div class="flex items-start justify-between gap-4">
            <div class="flex items-start gap-6">
                <div
                    class="w-[200px] shrink-0 overflow-hidden rounded-md border bg-muted"
                >
                    <img
                        v-if="posterUrl()"
                        :src="posterUrl() ?? ''"
                        :alt="movie.title"
                        class="aspect-[2/3] w-full object-cover"
                    />
                    <div
                        v-else
                        class="flex aspect-[2/3] w-full items-center justify-center bg-muted text-muted-foreground"
                    >
                        <Film class="size-12" />
                    </div>
                </div>

                <div class="min-w-0 flex-1 space-y-4">
                    <div>
                        <h2 class="text-3xl font-bold tracking-tight">
                            {{ movie.title }}
                            <span
                                v-if="movie.year"
                                class="font-normal text-muted-foreground"
                            >
                                ({{ movie.year }})
                            </span>
                        </h2>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Badge variant="outline">{{
                            movie.status ?? 'unknown'
                        }}</Badge>
                        <Badge
                            :variant="movie.monitored ? 'default' : 'secondary'"
                        >
                            {{ movie.monitored ? 'Monitored' : 'Unmonitored' }}
                        </Badge>
                        <Badge
                            :variant="movie.has_file ? 'default' : 'secondary'"
                        >
                            {{ movie.has_file ? 'File present' : 'Missing' }}
                        </Badge>
                    </div>

                    <p
                        v-if="movie.overview"
                        class="text-sm text-muted-foreground"
                    >
                        {{ movie.overview }}
                    </p>

                    <dl class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt
                                class="text-xs tracking-wider text-muted-foreground uppercase"
                            >
                                Studio
                            </dt>
                            <dd class="mt-1 text-sm">
                                {{ movie.studio ?? '-' }}
                            </dd>
                        </div>
                        <div>
                            <dt
                                class="text-xs tracking-wider text-muted-foreground uppercase"
                            >
                                Runtime
                            </dt>
                            <dd class="mt-1 flex items-center gap-1 text-sm">
                                <Clock class="size-3.5 text-muted-foreground" />
                                {{
                                    movie.runtime ? `${movie.runtime} min` : '-'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt
                                class="text-xs tracking-wider text-muted-foreground uppercase"
                            >
                                Root Folder
                            </dt>
                            <dd class="mt-1 text-sm break-all">
                                {{ movie.root_folder_path ?? '-' }}
                            </dd>
                        </div>
                        <div>
                            <dt
                                class="text-xs tracking-wider text-muted-foreground uppercase"
                            >
                                Quality Profile
                            </dt>
                            <dd class="mt-1 text-sm">
                                {{ movie.quality_profile_id ?? '-' }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <Link :href="MovieController.index.url()">
                    <Button variant="outline">Back</Button>
                </Link>
                <a
                    v-if="props.movie.title_slug"
                    :href="`${props.connection.url}/movie/${props.movie.title_slug}`"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <Button variant="outline" size="sm">
                        <ExternalLink class="mr-2 size-4" />
                        Open in Radarr
                    </Button>
                </a>
                <Dialog v-model:open="deleteDialogOpen">
                    <DialogTrigger as-child>
                        <Button variant="destructive">
                            <Trash2 class="mr-2 size-4" />
                            Delete
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Delete {{ movie.title }}?</DialogTitle>
                            <DialogDescription>
                                This will remove the movie from Radarr. This
                                action cannot be undone.
                            </DialogDescription>
                        </DialogHeader>
                        <div class="flex items-center gap-2 py-2">
                            <Checkbox id="delete_files" v-model="deleteFiles" />
                            <label
                                for="delete_files"
                                class="text-sm leading-none"
                            >
                                Also delete files from disk
                            </label>
                        </div>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                @click="deleteDialogOpen = false"
                                >Cancel</Button
                            >
                            <Button variant="destructive" @click="confirmDelete"
                                >Confirm</Button
                            >
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </div>

        <Card v-if="movie.movie_file">
            <CardHeader>
                <div class="flex items-center gap-2">
                    <HardDrive class="size-4 text-muted-foreground" />
                    <CardTitle>File Info</CardTitle>
                </div>
            </CardHeader>
            <CardContent>
                <dl class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt
                            class="text-xs tracking-wider text-muted-foreground uppercase"
                        >
                            Quality
                        </dt>
                        <dd class="mt-1 text-sm">
                            {{ movie.movie_file.quality ?? '-' }}
                        </dd>
                    </div>
                    <div>
                        <dt
                            class="text-xs tracking-wider text-muted-foreground uppercase"
                        >
                            Size
                        </dt>
                        <dd class="mt-1 text-sm">
                            {{ formatSize(movie.movie_file.size) }}
                        </dd>
                    </div>
                    <div>
                        <dt
                            class="text-xs tracking-wider text-muted-foreground uppercase"
                        >
                            Relative Path
                        </dt>
                        <dd class="mt-1 text-sm break-all">
                            {{ movie.movie_file.relative_path ?? '-' }}
                        </dd>
                    </div>
                </dl>
            </CardContent>
        </Card>
    </div>
</template>
