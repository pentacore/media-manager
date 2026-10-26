<script setup lang="ts">
import { Activity, ChevronRight, HardDrive } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import { MonitorButton, SearchButton } from '@/components/library-actions';
import { Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { useCan } from '@/composables/useCan';
import { formatBytes } from '@/lib/format';
import SeriesEpisodeRow from './SeriesEpisodeRow.vue';
import {
    episodeIdsForSeason,
    episodesForSeason,
    seasonEpisodesMonitored,
} from './seriesHelpers';
import type { Episode, Season } from './types';

defineProps<{
    season: Season;
    /** Every episode of the series; the card picks its season's. */
    episodes: Episode[];
    seriesId: number;
    connectionId: number;
    /** Expanded; the page keeps it, so a webhook reload leaves it open. */
    open: boolean;
}>();

const emit = defineEmits<{
    toggle: [];
    interactive: [
        heading: string,
        seasonNumber: number | null,
        episodeId: number | null,
    ];
    replace: [episode: Episode];
}>();

const { can } = useCan();

/**
 * reka-ui's CollapsibleContent sets the id the trigger's aria-controls reads
 * in its own setup — after the trigger rendered — and not reactively. Inline
 * in the page, the trigger sat in the seasons v-for, so the next page render
 * refreshed it; here the trigger re-keys once on mount to pick the id up.
 * The remount runs in the mount flush, before the browser paints, so no
 * frame shows the old trigger and no click can reach it. The trigger is
 * built and torn down twice, so keep mount side effects (listeners, fetches,
 * focus) out of it. Remove the re-key once
 * `components/ui/collapsible/CollapsibleTrigger.vue` seeds `contentId`
 * itself (on the roadmap).
 */
const mounted = ref(false);

onMounted(() => {
    mounted.value = true;
});
</script>

<template>
    <div
        class="overflow-hidden rounded-xl border border-border bg-card"
        :data-season-card="season.season_number"
    >
        <Collapsible :open="open">
            <CollapsibleTrigger :key="mounted ? 'mounted' : 'initial'" as-child>
                <button
                    type="button"
                    class="flex w-full cursor-pointer items-center justify-between gap-4 px-4 py-3 text-left transition-colors hover:bg-bg-hover"
                    @click="emit('toggle')"
                >
                    <div class="flex items-center gap-3">
                        <ChevronRight
                            class="size-4 text-muted-foreground transition-transform"
                            :class="open ? 'rotate-90' : ''"
                        />
                        <span class="text-[14px] font-semibold">
                            {{
                                season.season_number === 0
                                    ? 'Specials'
                                    : `Season ${season.season_number}`
                            }}
                        </span>
                        <Pill
                            :data-season-pill="season.season_number"
                            :variant="
                                seasonEpisodesMonitored(
                                    episodes,
                                    season.season_number,
                                )
                                    ? 'ok'
                                    : 'default'
                            "
                            :dot="
                                seasonEpisodesMonitored(
                                    episodes,
                                    season.season_number,
                                )
                            "
                        >
                            {{
                                seasonEpisodesMonitored(
                                    episodes,
                                    season.season_number,
                                )
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
                                {{ season.episode_file_count }}
                                /
                                {{ season.episode_count }}
                            </span>
                        </span>
                        <span class="flex items-center gap-1.5">
                            <HardDrive class="size-3.5" />
                            <span class="font-mono-tabular">
                                {{ formatBytes(season.size_on_disk) }}
                            </span>
                        </span>
                    </div>
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <div class="border-t border-border px-4 py-3">
                    <div
                        v-if="can('manage-library')"
                        class="mb-3 flex flex-wrap items-center gap-2 touch:gap-3"
                        :data-season-actions="season.season_number"
                    >
                        <template
                            v-if="
                                episodeIdsForSeason(
                                    episodes,
                                    season.season_number,
                                ).length > 0
                            "
                        >
                            <MonitorButton
                                service="sonarr"
                                :connection-id="connectionId"
                                :series-id="seriesId"
                                :episode-ids="
                                    episodeIdsForSeason(
                                        episodes,
                                        season.season_number,
                                    )
                                "
                                :season-number="season.season_number"
                                :monitored="
                                    seasonEpisodesMonitored(
                                        episodes,
                                        season.season_number,
                                    )
                                "
                                :label="
                                    seasonEpisodesMonitored(
                                        episodes,
                                        season.season_number,
                                    )
                                        ? 'Season monitored'
                                        : 'Season unmonitored'
                                "
                            />
                            <SearchButton
                                service="sonarr"
                                :connection-id="connectionId"
                                command="season_search"
                                :series-id="seriesId"
                                :season-number="season.season_number"
                                label="Search season"
                            />
                        </template>
                        <span
                            v-else
                            class="text-[12px] text-muted-foreground"
                            data-season-no-episodes
                        >
                            No episodes yet
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-7 text-xs"
                            data-interactive-search
                            @click="
                                emit(
                                    'interactive',
                                    season.season_number === 0
                                        ? 'Specials'
                                        : `Season ${season.season_number}`,
                                    season.season_number,
                                    null,
                                )
                            "
                        >
                            Interactive search
                        </Button>
                    </div>
                    <div class="space-y-2">
                        <SeriesEpisodeRow
                            v-for="episode in episodesForSeason(
                                episodes,
                                season.season_number,
                            )"
                            :key="`${episode.season_number}-${episode.episode_number}`"
                            :episode="episode"
                            :series-id="seriesId"
                            :connection-id="connectionId"
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
                            v-if="
                                episodesForSeason(
                                    episodes,
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
</template>
