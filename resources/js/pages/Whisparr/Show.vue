<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, ExternalLink } from '@lucide/vue';
import { computed } from 'vue';
import WhisparrController from '@/actions/App/Http/Controllers/Whisparr/WhisparrController';
import { Pill, Poster, SvcChip } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useWhisparrBlur } from '@/composables/useWhisparrBlur';
import { formatSize } from '@/lib/arr';
import { dashboard } from '@/routes';
import type {
    QualityProfileOption,
    WhisparrConnection,
    WhisparrItemDetail,
    WhisparrScenes,
} from '@/types';

const props = defineProps<{
    connection: WhisparrConnection;
    item: WhisparrItemDetail;
    scenes?: WhisparrScenes;
    qualityProfiles?: QualityProfileOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Media', href: dashboard().url },
            { title: 'Whisparr', href: WhisparrController.index.url() },
        ],
    },
});

const { blurPosters } = useWhisparrBlur();

const profileName = computed(() => {
    if (props.item.quality_profile_id === null || !props.qualityProfiles) {
        return '—';
    }

    return (
        props.qualityProfiles.find(
            (profile) => profile.id === props.item.quality_profile_id,
        )?.name ?? '—'
    );
});
</script>

<template>
    <Head :title="item.title" />

    <div class="flex flex-col gap-6 p-5">
        <div class="flex items-center justify-between">
            <Link :href="WhisparrController.index.url()">
                <Button variant="ghost" size="sm" class="h-8 text-xs">
                    <ArrowLeft class="size-3.5" />
                    Back to Whisparr
                </Button>
            </Link>
            <a :href="connection.url" target="_blank" rel="noopener noreferrer">
                <Button variant="outline" size="sm" class="h-8 text-xs">
                    <ExternalLink class="size-3.5" />
                    Open Whisparr
                </Button>
            </a>
        </div>

        <div class="rounded-xl border border-border bg-card p-6">
            <div class="flex flex-col gap-6 md:flex-row">
                <div class="w-[180px] shrink-0">
                    <Poster
                        :hint="item.title"
                        :src="item.poster_url"
                        :blurred="blurPosters"
                        size="full"
                    />
                </div>

                <div class="flex-1 space-y-4">
                    <div>
                        <div class="mb-1.5 flex items-center gap-2">
                            <SvcChip id="whisparr" label="Whisparr" />
                            <Pill>{{
                                item.kind === 'site' ? 'Site' : 'Movie'
                            }}</Pill>
                            <Pill v-if="!item.monitored">Unmonitored</Pill>
                            <Pill v-else-if="!item.has_file" variant="warn"
                                >Missing</Pill
                            >
                        </div>
                        <h1
                            class="text-[22px] leading-tight font-semibold tracking-tight"
                            data-whisparr-show-title
                        >
                            {{ item.title }}
                            <span
                                v-if="item.year"
                                class="font-mono-tabular text-[15px] font-normal text-muted-foreground"
                                >({{ item.year }})</span
                            >
                        </h1>
                    </div>

                    <p
                        v-if="item.overview"
                        class="max-w-[720px] text-[13px] text-muted-foreground"
                    >
                        {{ item.overview }}
                    </p>

                    <dl class="grid gap-3 text-[13px] sm:grid-cols-3">
                        <div>
                            <dt class="text-[11.5px] text-muted-foreground">
                                Size on disk
                            </dt>
                            <dd class="font-mono-tabular">
                                {{ formatSize(item.size_bytes) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11.5px] text-muted-foreground">
                                Quality profile
                            </dt>
                            <dd>
                                <Skeleton
                                    v-if="qualityProfiles === undefined"
                                    class="h-4 w-24"
                                />
                                <template v-else>{{ profileName }}</template>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11.5px] text-muted-foreground">
                                Path
                            </dt>
                            <dd
                                class="font-mono-tabular text-[12px] break-all"
                                data-whisparr-path
                            >
                                {{ item.path ?? '—' }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>

        <section
            v-if="connection.version === 'v2'"
            class="rounded-xl border border-border bg-card"
        >
            <div
                class="border-b border-border px-4 py-3 text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
            >
                Scenes
            </div>
            <div v-if="scenes === undefined" class="space-y-2 p-4">
                <Skeleton v-for="n in 4" :key="n" class="h-8 w-full" />
            </div>
            <div
                v-else-if="scenes.error"
                class="p-4 text-sm text-destructive"
                data-whisparr-scenes-error
            >
                {{ scenes.error }}
            </div>
            <p
                v-else-if="scenes.groups.length === 0"
                class="p-4 text-sm text-fg-subtle"
            >
                No scenes yet.
            </p>
            <div v-else class="divide-y divide-border">
                <div
                    v-for="group in scenes.groups"
                    :key="group.year"
                    class="px-4 py-3"
                    :data-whisparr-scene-year="group.year"
                >
                    <div class="mb-2 text-[12px] font-semibold">
                        {{ group.year > 0 ? group.year : 'Unknown year' }}
                    </div>
                    <ul class="space-y-1.5">
                        <li
                            v-for="scene in group.scenes"
                            :key="scene.id"
                            class="flex items-center justify-between gap-3 text-[13px]"
                        >
                            <span>{{
                                scene.title ?? `Scene #${scene.id}`
                            }}</span>
                            <span class="flex items-center gap-2">
                                <span
                                    class="font-mono-tabular text-[11.5px] text-fg-subtle"
                                    >{{ scene.air_date ?? '—' }}</span
                                >
                                <Pill v-if="scene.has_file" variant="ok"
                                    >on disk</Pill
                                >
                                <Pill v-else-if="scene.monitored">missing</Pill>
                                <Pill v-else>unmonitored</Pill>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </section>
    </div>
</template>
