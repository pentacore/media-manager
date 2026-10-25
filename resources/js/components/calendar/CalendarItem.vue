<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { MonitorButton, SearchButton } from '@/components/library-actions';
import { Pill, Poster } from '@/components/mm';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import type { CalendarItem } from '@/types';

const props = withDefaults(
    defineProps<{ item: CalendarItem; compact?: boolean }>(),
    { compact: false },
);

const { can } = useCan();
const { formatTime } = useDateTime();

const STATES = {
    downloaded: { variant: 'ok', label: 'Downloaded' },
    missing: { variant: 'warn', label: 'Missing' },
    upcoming: { variant: 'info', label: 'Upcoming' },
    unmonitored: { variant: 'default', label: 'Unmonitored' },
} as const;

const state = computed(() => STATES[props.item.state]);
</script>

<template>
    <span
        v-if="compact"
        class="block truncate rounded px-1 py-0.5 text-[11px]"
        :data-calendar-item="item.key"
    >
        <span class="font-medium">{{ item.title }}</span>
        <span class="text-muted-foreground"> {{ item.code }}</span>
    </span>
    <div
        v-else
        class="flex items-center gap-3 px-3 py-2.5 max-sm:flex-wrap max-sm:gap-y-2"
        :data-calendar-item="item.key"
    >
        <Poster :hint="item.title" size="sm" :src="item.poster_url" />
        <div class="min-w-0 flex-1 max-sm:basis-[calc(100%-3rem)]">
            <component
                :is="item.library_url ? Link : 'span'"
                :href="item.library_url ?? undefined"
                class="block truncate text-[13px] font-medium max-sm:break-words max-sm:whitespace-normal"
                data-calendar-item-title
            >
                {{ item.title }}
            </component>
            <div class="truncate text-[11.5px] text-muted-foreground">
                <span class="font-mono-tabular">{{ item.code }}</span>
                <template v-if="item.episode_title">
                    · {{ item.episode_title }}</template
                >
                <template v-if="item.service === 'sonarr'">
                    · {{ formatTime(item.air_date_utc) }}</template
                >
                <template v-if="item.instance"> · {{ item.instance }}</template>
            </div>
        </div>
        <Pill :variant="state.variant">{{ state.label }}</Pill>
        <div
            v-if="can('manage-library')"
            class="flex items-center gap-1.5 max-sm:ml-auto"
        >
            <SearchButton
                v-if="item.service === 'sonarr' && item.episode_id !== null"
                service="sonarr"
                :connection-id="item.service_connection_id"
                command="episode_search"
                :series-id="item.series_id"
                :episode-ids="[item.episode_id]"
            />
            <SearchButton
                v-else-if="item.movie_id !== null"
                service="radarr"
                :connection-id="item.service_connection_id"
                command="movies_search"
                :movie-ids="[item.movie_id]"
            />
            <MonitorButton
                v-if="item.service === 'sonarr' && item.episode_id !== null"
                service="sonarr"
                :connection-id="item.service_connection_id"
                :series-id="item.series_id"
                :episode-ids="[item.episode_id]"
                :monitored="item.episode_monitored ?? item.monitored"
            />
            <MonitorButton
                v-else-if="item.movie_id !== null"
                service="radarr"
                :connection-id="item.service_connection_id"
                :item-id="item.movie_id"
                :monitored="item.monitored"
            />
        </div>
    </div>
</template>
