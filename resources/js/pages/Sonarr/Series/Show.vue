<script setup lang="ts">
import { Head, Link, router, WhenVisible } from '@inertiajs/vue3';
import {
    Calendar,
    HardDrive,
    Activity,
    Trash2,
    ChevronRight,
    ArrowLeft,
    ExternalLink,
} from 'lucide-vue-next';
import { ref } from 'vue';
import SeriesController from '@/actions/App/Http/Controllers/Media/SeriesController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
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

interface Episode {
    id: number | null;
    season_number: number;
    episode_number: number;
    title: string | null;
    air_date: string | null;
    has_file: boolean;
    monitored: boolean;
    overview: string | null;
}

interface Season {
    season_number: number;
    monitored: boolean;
    episode_count: number;
    episode_file_count: number;
    size_on_disk: number;
}

interface SeriesDetail {
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
    overview: string | null;
    network: string | null;
    runtime: number | null;
    root_folder_path: string | null;
    seasons: Season[];
}

const props = defineProps<{
    connection: { url: string };
    series: SeriesDetail;
    episodes?: Episode[];
    qualityProfiles?: QualityProfile[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Series', href: SeriesController.index.url() },
        ],
    },
});

const deleteFiles = ref(false);
const deleteDialogOpen = ref(false);
const openSeasons = ref<Record<number, boolean>>({});

function posterUrl(): string | null {
    const poster = props.series.images.find(
        (image) => image.coverType === 'poster',
    );

    return poster?.remoteUrl ?? poster?.url ?? null;
}

function formatSize(bytes: number): string {
    if (!bytes || bytes <= 0) {
        return '-';
    }

    const gb = bytes / 1024 ** 3;

    if (gb >= 1) {
        return `${gb.toFixed(1)} GB`;
    }

    const mb = bytes / 1024 ** 2;

    return `${mb.toFixed(0)} MB`;
}

function qualityName(): string {
    if (!props.series.quality_profile_id || !props.qualityProfiles) {
        return '-';
    }

    return (
        props.qualityProfiles.find(
            (profile) => profile.id === props.series.quality_profile_id,
        )?.name ?? '-'
    );
}

function episodesForSeason(seasonNumber: number): Episode[] {
    return (props.episodes ?? [])
        .filter((episode) => episode.season_number === seasonNumber)
        .sort((a, b) => a.episode_number - b.episode_number);
}

function toggleSeason(seasonNumber: number) {
    openSeasons.value[seasonNumber] = !openSeasons.value[seasonNumber];
}

function confirmDelete() {
    router.delete(SeriesController.destroy.url(props.series.id), {
        data: { delete_files: deleteFiles.value },
        preserveScroll: true,
        onFinish: () => {
            deleteDialogOpen.value = false;
        },
    });
}

function sonarrSeriesUrl(): string | null {
    if (!props.series.title_slug) {
        return null;
    }

    return `${props.connection.url}/series/${props.series.title_slug}`;
}
</script>

<template>
    <Head :title="series.title" />

    <div class="space-y-6 p-6">
        <div class="flex items-center justify-between">
            <Link :href="SeriesController.index.url()">
                <Button variant="ghost" size="sm">
                    <ArrowLeft class="mr-2 size-4" />
                    Back to Series
                </Button>
            </Link>
            <div class="flex items-center gap-2">
                <a
                    v-if="series.title_slug"
                    :href="sonarrSeriesUrl() ?? undefined"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <Button variant="outline" size="sm">
                        <ExternalLink class="mr-2 size-4" />
                        Open in Sonarr
                    </Button>
                </a>
                <Dialog v-model:open="deleteDialogOpen">
                    <DialogTrigger as-child>
                        <Button variant="destructive" size="sm">
                            <Trash2 class="mr-2 size-4" />
                            Delete
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Delete {{ series.title }}?</DialogTitle>
                            <DialogDescription>
                                This will remove the series from Sonarr. This
                                action cannot be undone.
                            </DialogDescription>
                        </DialogHeader>
                        <div class="flex items-center gap-2 py-2">
                            <Checkbox id="delete_files" v-model="deleteFiles" />
                            <Label for="delete_files"
                                >Also delete files on disk</Label
                            >
                        </div>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                @click="deleteDialogOpen = false"
                                >Cancel</Button
                            >
                            <Button variant="destructive" @click="confirmDelete"
                                >Delete</Button
                            >
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </div>

        <div class="flex flex-col gap-6 md:flex-row">
            <div class="shrink-0">
                <img
                    v-if="posterUrl()"
                    :src="posterUrl() ?? ''"
                    :alt="series.title"
                    class="w-[200px] rounded-md border bg-muted object-cover shadow"
                />
                <div
                    v-else
                    class="flex h-[300px] w-[200px] items-center justify-center rounded-md border bg-muted text-muted-foreground"
                >
                    No poster
                </div>
            </div>

            <div class="flex-1 space-y-4">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight">
                        {{ series.title }}
                        <span
                            v-if="series.year"
                            class="font-normal text-muted-foreground"
                            >({{ series.year }})</span
                        >
                    </h1>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <Badge v-if="series.status" variant="default">{{
                            series.status
                        }}</Badge>
                        <Badge
                            :variant="
                                series.monitored ? 'default' : 'secondary'
                            "
                        >
                            {{ series.monitored ? 'Monitored' : 'Unmonitored' }}
                        </Badge>
                        <Badge v-if="series.network" variant="outline">{{
                            series.network
                        }}</Badge>
                    </div>
                </div>

                <p v-if="series.overview" class="text-muted-foreground">
                    {{ series.overview }}
                </p>

                <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <div>
                        <p class="text-xs text-muted-foreground">Network</p>
                        <p class="font-medium">{{ series.network ?? '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Runtime</p>
                        <p class="font-medium">
                            {{ series.runtime ? `${series.runtime} min` : '-' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Root Folder</p>
                        <p
                            class="truncate font-medium"
                            :title="series.root_folder_path ?? ''"
                        >
                            {{ series.root_folder_path ?? '-' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">
                            Quality Profile
                        </p>
                        <p class="font-medium">{{ qualityName() }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Files</p>
                        <p class="font-medium">
                            {{ series.episode_file_count }} /
                            {{ series.episode_count }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">
                            Size on Disk
                        </p>
                        <p class="font-medium">
                            {{ formatSize(series.size_on_disk) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Seasons</p>
                        <p class="font-medium">{{ series.season_count }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-3">
            <h2 class="text-xl font-semibold tracking-tight">Seasons</h2>

            <WhenVisible data="episodes">
                <template #fallback>
                    <Card
                        v-for="season in series.seasons"
                        :key="`skeleton-${season.season_number}`"
                    >
                        <CardHeader>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <ChevronRight class="size-4" />
                                    <CardTitle class="text-base">
                                        {{
                                            season.season_number === 0
                                                ? 'Specials'
                                                : `Season ${season.season_number}`
                                        }}
                                    </CardTitle>
                                    <Skeleton class="h-5 w-20 rounded-full" />
                                </div>
                                <Skeleton class="h-4 w-32" />
                            </div>
                        </CardHeader>
                    </Card>
                    <p
                        v-if="series.seasons.length === 0"
                        class="text-muted-foreground"
                    >
                        No seasons available.
                    </p>
                </template>

                <Card
                    v-for="season in series.seasons"
                    :key="season.season_number"
                >
                    <Collapsible
                        :open="openSeasons[season.season_number] ?? false"
                    >
                        <CollapsibleTrigger as-child>
                            <CardHeader
                                class="cursor-pointer hover:bg-muted/50"
                                @click="toggleSeason(season.season_number)"
                            >
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <ChevronRight
                                            class="size-4 transition-transform"
                                            :class="
                                                openSeasons[season.season_number]
                                                    ? 'rotate-90'
                                                    : ''
                                            "
                                        />
                                        <CardTitle class="text-base">
                                            {{
                                                season.season_number === 0
                                                    ? 'Specials'
                                                    : `Season ${season.season_number}`
                                            }}
                                        </CardTitle>
                                        <Badge
                                            :variant="
                                                season.monitored
                                                    ? 'default'
                                                    : 'secondary'
                                            "
                                        >
                                            {{
                                                season.monitored
                                                    ? 'Monitored'
                                                    : 'Unmonitored'
                                            }}
                                        </Badge>
                                    </div>
                                    <div
                                        class="flex items-center gap-4 text-sm text-muted-foreground"
                                    >
                                        <span class="flex items-center gap-1">
                                            <Activity class="size-4" />
                                            {{ season.episode_file_count }} /
                                            {{ season.episode_count }}
                                        </span>
                                        <span class="flex items-center gap-1">
                                            <HardDrive class="size-4" />
                                            {{ formatSize(season.size_on_disk) }}
                                        </span>
                                    </div>
                                </div>
                            </CardHeader>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <CardContent>
                                <div class="space-y-2">
                                    <div
                                        v-for="episode in episodesForSeason(
                                            season.season_number,
                                        )"
                                        :key="`${episode.season_number}-${episode.episode_number}`"
                                        class="flex items-center justify-between rounded-md border p-3"
                                    >
                                        <div class="min-w-0 flex-1">
                                            <p class="font-medium">
                                                <span
                                                    class="text-muted-foreground"
                                                    >{{
                                                        episode.episode_number
                                                    }}.</span
                                                >
                                                {{ episode.title ?? 'TBA' }}
                                            </p>
                                            <p
                                                v-if="episode.overview"
                                                class="truncate text-sm text-muted-foreground"
                                            >
                                                {{ episode.overview }}
                                            </p>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <span
                                                v-if="episode.air_date"
                                                class="flex items-center gap-1 text-sm text-muted-foreground"
                                            >
                                                <Calendar class="size-4" />
                                                {{ episode.air_date }}
                                            </span>
                                            <Badge
                                                :variant="
                                                    episode.has_file
                                                        ? 'default'
                                                        : 'outline'
                                                "
                                            >
                                                {{
                                                    episode.has_file
                                                        ? 'Downloaded'
                                                        : 'Missing'
                                                }}
                                            </Badge>
                                        </div>
                                    </div>
                                    <p
                                        v-if="
                                            episodesForSeason(
                                                season.season_number,
                                            ).length === 0
                                        "
                                        class="py-4 text-center text-sm text-muted-foreground"
                                    >
                                        No episodes available.
                                    </p>
                                </div>
                            </CardContent>
                        </CollapsibleContent>
                    </Collapsible>
                </Card>

                <p
                    v-if="series.seasons.length === 0"
                    class="text-muted-foreground"
                >
                    No seasons available.
                </p>
            </WhenVisible>
        </div>
    </div>
</template>
