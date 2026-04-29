<script setup lang="ts">
import { Head, Link, router, WhenVisible } from '@inertiajs/vue3';
import {
    Activity,
    ArrowLeft,
    Calendar,
    ChevronRight,
    ExternalLink,
    HardDrive,
    Trash2,
} from 'lucide-vue-next';
import { ref } from 'vue';
import SeriesController from '@/actions/App/Http/Controllers/Media/SeriesController';
import { Pill, Poster, StatusPill } from '@/components/mm';
import { Button } from '@/components/ui/button';
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

    <div class="flex flex-col gap-6 p-5">
        <div class="flex items-center justify-between">
            <Link :href="SeriesController.index.url()">
                <Button variant="ghost" size="sm" class="h-8 text-xs">
                    <ArrowLeft class="size-3.5" />
                    Back to series
                </Button>
            </Link>
            <div class="flex items-center gap-2">
                <a
                    v-if="series.title_slug"
                    :href="sonarrSeriesUrl() ?? undefined"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <Button variant="outline" size="sm" class="h-8 text-xs">
                        <ExternalLink class="size-3.5" />
                        Open in Sonarr
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
                            <DialogTitle>
                                Delete {{ series.title }}?
                            </DialogTitle>
                            <DialogDescription>
                                Removes the series from Sonarr. Cannot be
                                undone.
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
                            >
                                Cancel
                            </Button>
                            <Button
                                variant="destructive"
                                @click="confirmDelete"
                            >
                                Delete
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
                        :alt="series.title"
                        class="w-[180px] rounded-md border border-border bg-muted object-cover"
                    />
                    <Poster v-else :hint="series.title" size="lg" />
                </div>

                <div class="flex-1 space-y-4">
                    <div>
                        <h1
                            class="text-[22px] leading-tight font-semibold tracking-tight"
                        >
                            {{ series.title }}
                            <span
                                v-if="series.year"
                                class="font-mono-tabular text-[15px] font-normal text-muted-foreground"
                            >
                                ({{ series.year }})
                            </span>
                        </h1>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <StatusPill
                                v-if="series.status"
                                :status="series.status"
                            />
                            <Pill
                                :variant="series.monitored ? 'ok' : 'default'"
                                :dot="series.monitored"
                            >
                                {{
                                    series.monitored
                                        ? 'Monitored'
                                        : 'Unmonitored'
                                }}
                            </Pill>
                            <Pill v-if="series.network">{{
                                series.network
                            }}</Pill>
                        </div>
                    </div>

                    <p
                        v-if="series.overview"
                        class="max-w-[640px] text-[13px] leading-relaxed text-muted-foreground"
                    >
                        {{ series.overview }}
                    </p>

                    <div
                        class="grid grid-cols-2 gap-x-6 gap-y-3 md:grid-cols-4"
                    >
                        <div>
                            <div
                                class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Network
                            </div>
                            <div class="mt-0.5 text-[13px]">
                                {{ series.network ?? '-' }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Runtime
                            </div>
                            <div class="font-mono-tabular mt-0.5 text-[13px]">
                                {{
                                    series.runtime
                                        ? `${series.runtime} min`
                                        : '-'
                                }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Quality profile
                            </div>
                            <div class="mt-0.5 text-[13px]">
                                {{ qualityName() }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Seasons
                            </div>
                            <div class="font-mono-tabular mt-0.5 text-[13px]">
                                {{ series.season_count }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Files
                            </div>
                            <div class="font-mono-tabular mt-0.5 text-[13px]">
                                {{ series.episode_file_count }} /
                                {{ series.episode_count }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Size on disk
                            </div>
                            <div class="font-mono-tabular mt-0.5 text-[13px]">
                                {{ formatSize(series.size_on_disk) }}
                            </div>
                        </div>
                        <div class="md:col-span-2">
                            <div
                                class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Root folder
                            </div>
                            <div
                                class="font-mono-tabular mt-0.5 truncate text-[13px]"
                                :title="series.root_folder_path ?? ''"
                            >
                                {{ series.root_folder_path ?? '-' }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-3">
            <h2 class="text-[18px] leading-tight font-semibold tracking-tight">
                Seasons
            </h2>

            <WhenVisible data="episodes">
                <template #fallback>
                    <div
                        v-for="season in series.seasons"
                        :key="`skeleton-${season.season_number}`"
                        class="flex items-center justify-between rounded-xl border border-border bg-card px-4 py-3"
                    >
                        <div class="flex items-center gap-3">
                            <ChevronRight
                                class="size-4 text-muted-foreground"
                            />
                            <span class="text-[14px] font-semibold">
                                {{
                                    season.season_number === 0
                                        ? 'Specials'
                                        : `Season ${season.season_number}`
                                }}
                            </span>
                            <Skeleton class="h-4 w-16 rounded-full" />
                        </div>
                        <Skeleton class="h-4 w-32" />
                    </div>
                    <p
                        v-if="series.seasons.length === 0"
                        class="text-[13px] text-muted-foreground"
                    >
                        No seasons available.
                    </p>
                </template>

                <div
                    v-for="season in series.seasons"
                    :key="season.season_number"
                    class="overflow-hidden rounded-xl border border-border bg-card"
                >
                    <Collapsible
                        :open="openSeasons[season.season_number] ?? false"
                    >
                        <CollapsibleTrigger as-child>
                            <button
                                type="button"
                                class="flex w-full cursor-pointer items-center justify-between gap-4 px-4 py-3 text-left transition-colors hover:bg-bg-hover"
                                @click="toggleSeason(season.season_number)"
                            >
                                <div class="flex items-center gap-3">
                                    <ChevronRight
                                        class="size-4 text-muted-foreground transition-transform"
                                        :class="
                                            openSeasons[season.season_number]
                                                ? 'rotate-90'
                                                : ''
                                        "
                                    />
                                    <span class="text-[14px] font-semibold">
                                        {{
                                            season.season_number === 0
                                                ? 'Specials'
                                                : `Season ${season.season_number}`
                                        }}
                                    </span>
                                    <Pill
                                        :variant="
                                            season.monitored ? 'ok' : 'default'
                                        "
                                        :dot="season.monitored"
                                    >
                                        {{
                                            season.monitored
                                                ? 'Monitored'
                                                : 'Unmonitored'
                                        }}
                                    </Pill>
                                </div>
                                <div
                                    class="flex items-center gap-4 text-[12px] text-muted-foreground"
                                >
                                    <span class="flex items-center gap-1.5">
                                        <Activity class="size-3.5" />
                                        <span class="font-mono-tabular">
                                            {{ season.episode_file_count }} /
                                            {{ season.episode_count }}
                                        </span>
                                    </span>
                                    <span class="flex items-center gap-1.5">
                                        <HardDrive class="size-3.5" />
                                        <span class="font-mono-tabular">
                                            {{
                                                formatSize(season.size_on_disk)
                                            }}
                                        </span>
                                    </span>
                                </div>
                            </button>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <div class="border-t border-border px-4 py-3">
                                <div class="space-y-2">
                                    <div
                                        v-for="episode in episodesForSeason(
                                            season.season_number,
                                        )"
                                        :key="`${episode.season_number}-${episode.episode_number}`"
                                        class="flex items-center justify-between gap-4 rounded-md border border-border bg-bg-hover/30 px-3 py-2"
                                    >
                                        <div class="min-w-0 flex-1">
                                            <p class="text-[13px] font-medium">
                                                <span
                                                    class="font-mono-tabular text-muted-foreground"
                                                >
                                                    {{
                                                        episode.episode_number
                                                    }}.
                                                </span>
                                                {{ episode.title ?? 'TBA' }}
                                            </p>
                                            <p
                                                v-if="episode.overview"
                                                class="truncate text-[12px] text-muted-foreground"
                                            >
                                                {{ episode.overview }}
                                            </p>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <span
                                                v-if="episode.air_date"
                                                class="font-mono-tabular flex items-center gap-1.5 text-[12px] text-muted-foreground"
                                            >
                                                <Calendar class="size-3.5" />
                                                {{ episode.air_date }}
                                            </span>
                                            <Pill
                                                :variant="
                                                    episode.has_file
                                                        ? 'ok'
                                                        : 'warn'
                                                "
                                            >
                                                {{
                                                    episode.has_file
                                                        ? 'Downloaded'
                                                        : 'Missing'
                                                }}
                                            </Pill>
                                        </div>
                                    </div>
                                    <p
                                        v-if="
                                            episodesForSeason(
                                                season.season_number,
                                            ).length === 0
                                        "
                                        class="py-3 text-center text-[12px] text-muted-foreground"
                                    >
                                        No episodes available.
                                    </p>
                                </div>
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                </div>

                <p
                    v-if="series.seasons.length === 0"
                    class="text-[13px] text-muted-foreground"
                >
                    No seasons available.
                </p>
            </WhenVisible>
        </div>
    </div>
</template>
