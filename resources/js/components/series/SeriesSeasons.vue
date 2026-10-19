<script setup lang="ts">
import { Deferred } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { Skeleton } from '@/components/ui/skeleton';
import type { UpstreamList } from '@/types';
import SeriesSeasonCard from './SeriesSeasonCard.vue';
import type { Episode, SeriesDetail } from './types';

defineProps<{
    series: SeriesDetail;
    /** Deferred: undefined until it loads. */
    episodes?: UpstreamList<Episode>;
    connectionId: number;
    /** The page's expanded seasons, by season number. */
    openSeasons: Record<number, boolean>;
}>();

const emit = defineEmits<{
    toggle: [seasonNumber: number];
    interactive: [
        heading: string,
        seasonNumber: number | null,
        episodeId: number | null,
    ];
    replace: [episode: Episode];
}>();
</script>

<template>
    <div class="space-y-3">
        <h2 class="text-[18px] leading-tight font-semibold tracking-tight">
            Seasons
        </h2>

        <Deferred data="episodes">
            <template #fallback>
                <div
                    v-for="season in series.seasons"
                    :key="`skeleton-${season.season_number}`"
                    class="flex items-center justify-between rounded-xl border border-border bg-card px-4 py-3"
                    :data-season-skeleton="season.season_number"
                >
                    <div class="flex items-center gap-3">
                        <ChevronRight class="size-4 text-muted-foreground" />
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
            <p
                v-if="episodes?.error"
                class="rounded-xl border border-border bg-card px-4 py-3 text-[13px] text-destructive"
                data-episodes-error
            >
                {{ episodes.error }}
            </p>

            <template v-else>
                <SeriesSeasonCard
                    v-for="season in series.seasons"
                    :key="season.season_number"
                    :season="season"
                    :episodes="episodes?.items ?? []"
                    :series-id="series.id"
                    :connection-id="connectionId"
                    :open="openSeasons[season.season_number] ?? false"
                    @toggle="emit('toggle', season.season_number)"
                    @interactive="
                        (heading, seasonNumber, episodeId) =>
                            emit(
                                'interactive',
                                heading,
                                seasonNumber,
                                episodeId,
                            )
                    "
                    @replace="(episode) => emit('replace', episode)"
                />

                <p
                    v-if="series.seasons.length === 0"
                    class="text-[13px] text-muted-foreground"
                >
                    No seasons available.
                </p>
            </template>
        </Deferred>
    </div>
</template>
