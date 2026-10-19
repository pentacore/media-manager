<script setup lang="ts">
import { Calendar, Replace } from '@lucide/vue';
import { MonitorButton, SearchButton } from '@/components/library-actions';
import { Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCan } from '@/composables/useCan';
import { episodeCode } from './seriesHelpers';
import type { Episode } from './types';

defineProps<{
    episode: Episode;
    seriesId: number;
    connectionId: number;
}>();

const emit = defineEmits<{
    interactive: [
        heading: string,
        seasonNumber: number | null,
        episodeId: number | null,
    ];
    replace: [episode: Episode];
}>();

const { can } = useCan();
</script>

<template>
    <div
        class="flex items-center justify-between gap-4 rounded-md border border-border bg-bg-hover/30 px-3 py-2"
    >
        <div class="min-w-0 flex-1">
            <p class="text-[13px] font-medium">
                <span class="font-mono-tabular text-muted-foreground">
                    {{ episode.episode_number }}.
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
            <Pill :variant="episode.has_file ? 'ok' : 'warn'">
                {{ episode.has_file ? 'Downloaded' : 'Missing' }}
            </Pill>
            <div
                v-if="can('manage-library') && episode.id !== null"
                class="flex items-center gap-1.5"
                :data-episode-actions="episode.id"
            >
                <MonitorButton
                    service="sonarr"
                    :connection-id="connectionId"
                    :series-id="seriesId"
                    :episode-ids="[episode.id]"
                    :monitored="episode.monitored"
                />
                <SearchButton
                    service="sonarr"
                    :connection-id="connectionId"
                    command="episode_search"
                    :series-id="seriesId"
                    :episode-ids="[episode.id]"
                />
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-7 text-xs"
                    data-interactive-search
                    @click="
                        emit(
                            'interactive',
                            `${episodeCode(episode)} ${episode.title ?? ''}`.trim(),
                            null,
                            episode.id,
                        )
                    "
                >
                    Interactive
                </Button>
            </div>
            <TooltipProvider
                v-if="episode.has_file && can('manage-library')"
                :delay-duration="200"
            >
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            data-replacement-trigger
                            @click="emit('replace', episode)"
                        >
                            <Replace class="size-3.5" />
                            <span class="sr-only"> Replace file </span>
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent> Replace file </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </div>
    </div>
</template>
