<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Clock,
    ExternalLink,
    HardDrive,
    Trash2,
} from 'lucide-vue-next';
import { ref } from 'vue';
import MovieController from '@/actions/App/Http/Controllers/Media/MovieController';
import { Pill, Poster, StatusPill } from '@/components/mm';
import { Button } from '@/components/ui/button';
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

    <div class="flex flex-col gap-6 p-5">
        <div class="flex items-center justify-between">
            <Link :href="MovieController.index.url()">
                <Button variant="ghost" size="sm" class="h-8 text-xs">
                    <ArrowLeft class="size-3.5" />
                    Back to movies
                </Button>
            </Link>
            <div class="flex items-center gap-2">
                <a
                    v-if="movie.title_slug"
                    :href="`${connection.url}/movie/${movie.title_slug}`"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <Button variant="outline" size="sm" class="h-8 text-xs">
                        <ExternalLink class="size-3.5" />
                        Open in Radarr
                    </Button>
                </a>
                <Dialog v-model:open="deleteDialogOpen">
                    <DialogTrigger as-child>
                        <Button
                            variant="destructive"
                            size="sm"
                            class="h-8 text-xs"
                        >
                            <Trash2 class="size-3.5" />
                            Delete
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Delete {{ movie.title }}?</DialogTitle>
                            <DialogDescription>
                                Removes the movie from Radarr. Cannot be undone.
                            </DialogDescription>
                        </DialogHeader>
                        <div class="flex items-center gap-2 py-2">
                            <Checkbox id="delete_files" v-model="deleteFiles" />
                            <label
                                for="delete_files"
                                class="text-[13px] leading-none"
                            >
                                Also delete files from disk
                            </label>
                        </div>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                @click="deleteDialogOpen = false"
                            >
                                Cancel
                            </Button>
                            <Button
                                variant="destructive"
                                @click="confirmDelete"
                            >
                                Confirm
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-6">
            <div class="flex flex-col gap-6 md:flex-row">
                <div class="shrink-0">
                    <img
                        v-if="posterUrl()"
                        :src="posterUrl() ?? ''"
                        :alt="movie.title"
                        class="w-[180px] rounded-md border border-border bg-muted object-cover"
                    />
                    <Poster v-else :hint="movie.title" size="lg" />
                </div>

                <div class="flex-1 space-y-4">
                    <div>
                        <h1
                            class="text-[22px] leading-tight font-semibold tracking-tight"
                        >
                            {{ movie.title }}
                            <span
                                v-if="movie.year"
                                class="font-mono-tabular text-[15px] font-normal text-muted-foreground"
                            >
                                ({{ movie.year }})
                            </span>
                        </h1>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <StatusPill
                                v-if="movie.status"
                                :status="movie.status"
                            />
                            <Pill
                                :variant="movie.monitored ? 'ok' : 'default'"
                                :dot="movie.monitored"
                            >
                                {{
                                    movie.monitored
                                        ? 'Monitored'
                                        : 'Unmonitored'
                                }}
                            </Pill>
                            <Pill :variant="movie.has_file ? 'ok' : 'warn'">
                                {{
                                    movie.has_file ? 'File present' : 'Missing'
                                }}
                            </Pill>
                        </div>
                    </div>

                    <p
                        v-if="movie.overview"
                        class="max-w-[640px] text-[13px] leading-relaxed text-muted-foreground"
                    >
                        {{ movie.overview }}
                    </p>

                    <div
                        class="grid grid-cols-2 gap-x-6 gap-y-3 md:grid-cols-4"
                    >
                        <div>
                            <div
                                class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Studio
                            </div>
                            <div class="mt-0.5 text-[13px]">
                                {{ movie.studio ?? '-' }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Runtime
                            </div>
                            <div
                                class="font-mono-tabular mt-0.5 flex items-center gap-1 text-[13px]"
                            >
                                <Clock class="size-3.5 text-muted-foreground" />
                                {{
                                    movie.runtime ? `${movie.runtime} min` : '-'
                                }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Quality profile
                            </div>
                            <div class="font-mono-tabular mt-0.5 text-[13px]">
                                {{ movie.quality_profile_id ?? '-' }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Size on disk
                            </div>
                            <div class="font-mono-tabular mt-0.5 text-[13px]">
                                {{ formatSize(movie.size_on_disk) }}
                            </div>
                        </div>
                        <div class="md:col-span-4">
                            <div
                                class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Root folder
                            </div>
                            <div
                                class="font-mono-tabular mt-0.5 truncate text-[13px]"
                                :title="movie.root_folder_path ?? ''"
                            >
                                {{ movie.root_folder_path ?? '-' }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="movie.movie_file"
            class="rounded-xl border border-border bg-card p-6"
        >
            <div class="mb-4 flex items-center gap-2">
                <HardDrive class="size-4 text-muted-foreground" />
                <h2 class="text-[14px] font-semibold tracking-tight">
                    File info
                </h2>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <div
                        class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                    >
                        Quality
                    </div>
                    <div class="mt-0.5 text-[13px]">
                        {{ movie.movie_file.quality ?? '-' }}
                    </div>
                </div>
                <div>
                    <div
                        class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                    >
                        Size
                    </div>
                    <div class="font-mono-tabular mt-0.5 text-[13px]">
                        {{ formatSize(movie.movie_file.size) }}
                    </div>
                </div>
                <div>
                    <div
                        class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                    >
                        Relative path
                    </div>
                    <div class="font-mono-tabular mt-0.5 text-[13px] break-all">
                        {{ movie.movie_file.relative_path ?? '-' }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
