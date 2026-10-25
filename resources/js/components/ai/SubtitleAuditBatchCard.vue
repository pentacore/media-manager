<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Captions } from '@lucide/vue';
import { computed } from 'vue';
import SeriesController from '@/actions/App/Http/Controllers/Media/SeriesController';
import { Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { useAiChat } from '@/composables/useAiChat';
import { useConfirm } from '@/composables/useConfirm';
import type {
    ActionRequestStatus,
    SubtitleAuditEpisodeOutcome,
} from '@/typefinder';
import type {
    SubtitleAuditBatchCard,
    SubtitleAuditBatchEpisode,
} from './types';

const props = defineProps<{
    card: SubtitleAuditBatchCard;
    /** The batch's approve/decline request is in flight. */
    busy: boolean;
}>();

const emit = defineEmits<{
    (e: 'approve'): void;
    (e: 'reject'): void;
}>();

const { closeChat } = useAiChat();
const { confirm } = useConfirm();

const ACTION_STATUS_VARIANTS: Record<
    ActionRequestStatus,
    'ok' | 'warn' | 'danger'
> = {
    pending: 'warn',
    approved: 'ok',
    executing: 'ok',
    completed: 'ok',
    rejected: 'danger',
    failed: 'danger',
};

/** What a row without a queued release says instead of a release title. */
const OUTCOME_COPY: Record<
    Exclude<SubtitleAuditEpisodeOutcome, 'queued'>,
    string
> = {
    searching: 'Still searching',
    manual_pick: 'No confident match',
    nothing_found: 'No usable release',
    no_longer_missing: 'Subtitles present now',
    failed: 'Search failed',
};

const pendingCount = computed(
    () => props.card.pending_action_request_ids.length,
);

function actionStatusVariant(
    status: string,
): 'ok' | 'warn' | 'danger' | 'default' {
    return ACTION_STATUS_VARIANTS[status as ActionRequestStatus] ?? 'default';
}

function missingLanguages(episode: SubtitleAuditBatchEpisode): string {
    return episode.missing_languages
        .map((language) => language.toUpperCase())
        .join(', ');
}

function pickReleaseUrl(episode: SubtitleAuditBatchEpisode): string {
    return SeriesController.show.url(props.card.series_id, {
        query: {
            replace_season: episode.season_number,
            replace_episode: episode.episode_number,
        },
    });
}

async function declineAll(): Promise<void> {
    if (
        !(await confirm({
            title: `Decline ${pendingCount.value} subtitle ${pendingCount.value === 1 ? 'replacement' : 'replacements'}?`,
            description: `Nothing in ${props.card.series_title} is replaced. Manual picks stay open on the series page.`,
            confirmLabel: 'Decline all',
            destructive: true,
        }))
    ) {
        return;
    }

    emit('reject');
}
</script>

<template>
    <div
        class="mb-2 rounded-lg border border-border bg-bg-elev p-2.5"
        data-subtitle-audit-card
    >
        <div class="mb-2 flex items-center justify-between gap-2">
            <span class="flex min-w-0 items-center gap-1.5">
                <Captions class="size-3.5 shrink-0 text-fg-subtle" />
                <span
                    class="truncate text-[12.5px] font-medium"
                    data-subtitle-audit-title
                    >Subtitle audit · {{ card.series_title }}</span
                >
            </span>
            <Pill
                :variant="card.status === 'ready' ? 'ok' : 'info'"
                class="shrink-0 text-[10px]"
                data-subtitle-audit-status
            >
                {{ card.status }}
            </Pill>
        </div>

        <ul class="mb-2 flex flex-col divide-y divide-border">
            <li
                v-for="episode in card.episodes"
                :key="episode.label"
                class="flex items-center justify-between gap-2 py-1.5"
                data-subtitle-audit-episode
                :data-outcome="episode.outcome"
            >
                <div class="flex min-w-0 flex-col gap-0.5">
                    <span class="flex items-center gap-1.5 text-[12px]">
                        <span class="font-mono-tabular font-medium">{{
                            episode.label
                        }}</span>
                        <span
                            v-if="episode.missing_languages.length"
                            class="text-[11px] text-fg-subtle"
                            data-subtitle-audit-languages
                            >missing {{ missingLanguages(episode) }}</span
                        >
                    </span>
                    <span
                        v-if="episode.outcome === 'queued'"
                        class="truncate text-[11.5px] text-muted-foreground"
                        :title="episode.release_title ?? undefined"
                        data-subtitle-audit-release
                        >{{ episode.release_title }}</span
                    >
                    <span
                        v-else
                        class="text-[11.5px] text-muted-foreground"
                        data-subtitle-audit-outcome
                    >
                        {{ OUTCOME_COPY[episode.outcome] }}
                        <template v-if="episode.outcome === 'manual_pick'">
                            ·
                            <Link
                                :href="pickReleaseUrl(episode)"
                                class="touch-target inline-block text-foreground underline underline-offset-2 hover:text-accent"
                                data-subtitle-audit-pick
                                @click="closeChat()"
                                >Pick release</Link
                            >
                        </template>
                    </span>
                </div>
                <Pill
                    v-if="episode.action_status"
                    :variant="actionStatusVariant(episode.action_status)"
                    class="shrink-0 text-[10px]"
                    data-subtitle-audit-action-status
                >
                    {{
                        episode.action_status === 'changed'
                            ? 'Changed elsewhere'
                            : episode.action_status
                    }}
                </Pill>
            </li>
        </ul>

        <div v-if="pendingCount > 0" class="flex flex-wrap items-center gap-2">
            <Button
                size="sm"
                class="h-7 text-xs"
                :disabled="busy"
                data-subtitle-audit-approve
                @click="emit('approve')"
            >
                Approve all ({{ pendingCount }})
            </Button>
            <Button
                size="sm"
                variant="outline"
                class="h-7 text-xs"
                :disabled="busy"
                data-subtitle-audit-decline
                @click="declineAll"
            >
                Decline all
            </Button>
        </div>
    </div>
</template>
