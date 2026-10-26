<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Captions } from '@lucide/vue';
import { computed } from 'vue';
import type { SubtitleAuditBatchCard } from '@/components/ai/types';
import { formatCount } from '@/lib/format';
import {
    subtitleAuditFollowUps,
    subtitleAuditPickUrl,
} from '@/lib/subtitleAudit';

const props = defineProps<{
    /** The batch the queue is filtered to. */
    batch: SubtitleAuditBatchCard;
}>();

const followUps = computed(() => subtitleAuditFollowUps(props.batch.episodes));

/** "2 had no usable release, 1 failed." — empty when neither happened. */
const missesLine = computed(() => {
    const parts = [
        followUps.value.nothingFound > 0
            ? `${formatCount(followUps.value.nothingFound)} had no usable release`
            : null,
        followUps.value.failed > 0
            ? `${formatCount(followUps.value.failed)} failed`
            : null,
    ].filter((part): part is string => part !== null);

    return parts.length === 0 ? '' : `${parts.join(', ')}.`;
});
</script>

<template>
    <section
        class="rounded-lg border border-border bg-card p-4"
        data-subtitle-audit-manual-picks
    >
        <h2 class="mb-1 flex items-center gap-1.5 text-[13px] font-semibold">
            <Captions class="size-3.5 shrink-0 text-fg-subtle" />
            <span class="truncate"
                >Subtitle audit · {{ batch.series_title }}</span
            >
        </h2>

        <template v-if="followUps.manualPicks.length > 0">
            <p class="mb-2 text-xs text-muted-foreground">
                No confident release for
                {{ formatCount(followUps.manualPicks.length) }}
                {{
                    followUps.manualPicks.length === 1 ? 'episode' : 'episodes'
                }}
                — pick one by hand:
            </p>
            <ul class="mb-2 flex flex-wrap gap-x-4 gap-y-2">
                <li
                    v-for="episode in followUps.manualPicks"
                    :key="episode.label"
                    class="flex items-center gap-1.5 text-[12px]"
                >
                    <Link
                        :href="subtitleAuditPickUrl(batch.series_id, episode)"
                        class="font-mono-tabular touch-target font-medium text-foreground underline underline-offset-2 hover:text-accent"
                        data-subtitle-audit-pick
                        >{{ episode.label }}</Link
                    >
                    <span
                        v-if="episode.missing_languages.length"
                        class="text-[11px] text-fg-subtle"
                        >missing
                        {{
                            episode.missing_languages
                                .map((language) => language.toUpperCase())
                                .join(', ')
                        }}</span
                    >
                </li>
            </ul>
        </template>

        <p
            v-if="missesLine !== ''"
            class="text-xs text-muted-foreground"
            data-subtitle-audit-misses
        >
            {{ missesLine }}
        </p>
    </section>
</template>
