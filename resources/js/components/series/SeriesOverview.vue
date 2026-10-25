<script setup lang="ts">
import { QualityProfileSelect } from '@/components/library-actions';
import { Pill, Poster, StatusPill } from '@/components/mm';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan } from '@/composables/useCan';
import { formatBytes } from '@/lib/format';
import type { UpstreamList } from '@/types';
import { posterUrl } from './seriesHelpers';
import type { QualityProfile, SeriesDetail } from './types';

defineProps<{
    series: SeriesDetail;
    connectionId: number;
    /** Deferred: undefined until it loads. */
    qualityProfiles?: UpstreamList<QualityProfile>;
    /** Viewers only, deferred: undefined until it loads, null when unknown. */
    qualityProfileName?: string | null;
}>();

const { can } = useCan();
</script>

<template>
    <div class="rounded-xl border border-border bg-card p-6">
        <div class="flex flex-col gap-6 md:flex-row">
            <div class="shrink-0">
                <img
                    v-if="posterUrl(series)"
                    :src="posterUrl(series) ?? ''"
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
                            {{ series.monitored ? 'Monitored' : 'Unmonitored' }}
                        </Pill>
                        <Pill v-if="series.network">{{ series.network }}</Pill>
                    </div>
                </div>

                <p
                    v-if="series.overview"
                    class="max-w-[640px] text-[13px] leading-relaxed text-muted-foreground"
                >
                    {{ series.overview }}
                </p>

                <div class="grid grid-cols-2 gap-x-6 gap-y-3 md:grid-cols-4">
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
                            {{ series.runtime ? `${series.runtime} min` : '-' }}
                        </div>
                    </div>
                    <div v-if="can('manage-library')" class="max-sm:col-span-2">
                        <div
                            class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            Quality profile
                        </div>
                        <div class="mt-0.5 text-[13px]">
                            <QualityProfileSelect
                                service="sonarr"
                                :connection-id="connectionId"
                                :item-id="series.id"
                                :profiles="qualityProfiles?.items"
                                :error="qualityProfiles?.error"
                                :current-id="series.quality_profile_id"
                            />
                        </div>
                    </div>
                    <div v-else>
                        <div
                            class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            Quality profile
                        </div>
                        <div
                            class="mt-0.5 text-[13px]"
                            data-quality-profile-name
                        >
                            <Skeleton
                                v-if="qualityProfileName === undefined"
                                class="h-4 w-24"
                            />
                            <template v-else>{{
                                qualityProfileName ?? '—'
                            }}</template>
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
                            {{ formatBytes(series.size_on_disk) }}
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
</template>
