<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Inbox, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import BenchmarkCaseController from '@/actions/App/Http/Controllers/Admin/BenchmarkCaseController';
import MediaReplacementAttemptController from '@/actions/App/Http/Controllers/Admin/MediaReplacementAttemptController';
import {
    Field,
    InitialsAvatar,
    Pill,
    StatusPill,
    SvcChip,
    TimeStamp,
} from '@/components/mm';
import { Button } from '@/components/ui/button';
import { useCan } from '@/composables/useCan';
import { cn } from '@/lib/utils';
import ActionFailureDetails from './ActionFailureDetails.vue';
import ActionPipeline from './ActionPipeline.vue';
import {
    attemptVariant,
    isDestructive,
    payloadDetail,
    payloadTitle,
    rejectedBy,
    rejectionReason,
    replacementAffectedFiles,
    replacementConfidence,
    replacementEvidence,
    replacementIsSeasonPack,
    replacementLanguages,
    replacementSelectionMode,
    showsAiReasoning,
    svcId,
} from './actionRow';
import SecondOpinionVerdict from './SecondOpinionVerdict.vue';
import type { ActionRequestRow } from './types';

defineProps<{
    /** The row open in the pane; null shows the "select an action" hint. */
    selected: ActionRequestRow | null;
}>();

const emit = defineEmits<{
    approve: [id: number];
    reject: [id: number];
    retry: [row: ActionRequestRow];
}>();

const { isAdmin } = useCan();
const aiEnabled = computed(() => Boolean(usePage().props.ai?.enabled));

/**
 * A second opinion that reached a verdict can become a verifier benchmark
 * case; a skipped or failed check has no verdict.
 */
function canBenchmark(row: ActionRequestRow): boolean {
    return (
        isAdmin.value &&
        aiEnabled.value &&
        row.second_opinion != null &&
        !('skipped' in row.second_opinion) &&
        !row.second_opinion.failed
    );
}
</script>

<template>
    <aside class="lg:sticky lg:top-16">
        <div
            v-if="!selected"
            class="flex flex-col items-center gap-2 rounded-xl border border-border bg-card p-9 text-fg-subtle"
        >
            <Inbox class="size-5" />
            <span class="text-sm">Select an action to inspect.</span>
        </div>
        <div
            v-else
            class="overflow-hidden rounded-xl border border-border bg-card"
        >
            <div class="flex flex-col gap-1.5 border-b border-border p-4">
                <div class="flex items-center justify-between">
                    <span class="font-mono-tabular text-[11px] text-fg-subtle"
                        >act_{{ selected.id }}</span
                    >
                    <StatusPill :status="selected.status" />
                </div>
                <h2
                    class="font-serif text-[22px] leading-tight text-foreground italic"
                    data-action-title
                >
                    {{ payloadTitle(selected) }}
                </h2>
                <div class="flex items-center gap-2.5">
                    <SvcChip
                        :id="svcId(selected.target_service)"
                        :label="selected.target_service"
                    />
                    <span
                        :class="
                            cn(
                                'font-mono-tabular rounded border px-1.5 py-0.5 text-[11px]',
                                isDestructive(selected.type)
                                    ? 'border-destructive/35 text-destructive'
                                    : 'border-border text-fg-subtle',
                            )
                        "
                    >
                        {{ selected.type }}
                    </span>
                </div>
            </div>

            <div class="flex flex-col gap-3.5 p-4">
                <Field label="Trigger">
                    {{ selected.webhook_source ?? 'Manual' }}
                </Field>
                <Field label="Source → target">
                    <span
                        class="font-mono-tabular text-[12px] text-muted-foreground"
                    >
                        {{ selected.source_service }} →
                        {{ selected.target_service }}
                    </span>
                </Field>
                <Field
                    v-if="selected.status !== 'rejected'"
                    label="Approved by"
                    data-action-approved-by
                >
                    <div class="flex items-center gap-2">
                        <InitialsAvatar
                            :name="selected.approved_by ?? 'system'"
                            :size="20"
                        />
                        <span class="text-[13px]">{{
                            selected.approved_by ?? 'system'
                        }}</span>
                    </div>
                </Field>
                <Field
                    v-if="rejectedBy(selected)"
                    label="Rejected by"
                    data-action-rejected-by
                >
                    <div class="flex items-center gap-2">
                        <InitialsAvatar
                            :name="rejectedBy(selected) ?? ''"
                            :size="20"
                        />
                        <span class="text-[13px]">{{
                            rejectedBy(selected)
                        }}</span>
                    </div>
                </Field>
                <Field label="Created">
                    <TimeStamp :iso="selected.created_at" />
                </Field>
                <Field
                    v-if="
                        selected.status === 'rejected' &&
                        rejectionReason(selected)
                    "
                    label="Rejection reason"
                >
                    <span
                        class="text-[13px] whitespace-pre-line"
                        data-action-rejection-reason
                        >{{ rejectionReason(selected) }}</span
                    >
                </Field>
                <ActionFailureDetails
                    v-if="selected.status === 'failed'"
                    :request="selected"
                    :show-outcome-warning="isAdmin"
                />
                <p
                    v-if="payloadDetail(selected)"
                    class="text-[13px] leading-relaxed text-muted-foreground"
                    data-action-description
                >
                    {{ payloadDetail(selected) }}
                </p>

                <div
                    v-if="selected.description_verified === false"
                    class="flex gap-2.5 rounded-lg border border-warning/30 bg-warning/10 p-3 text-xs text-warning"
                    data-action-unverified
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    <span
                        >Target name could not be verified by the server.</span
                    >
                </div>

                <div
                    v-if="selected.details?.length"
                    class="flex flex-col gap-3.5"
                    data-action-details
                >
                    <Field
                        v-for="(detail, index) in selected.details"
                        :key="`${index}-${detail.label}`"
                        :label="detail.label"
                    >
                        <span class="text-[13px]">{{ detail.value }}</span>
                    </Field>
                </div>

                <div
                    v-if="showsAiReasoning(selected)"
                    class="flex gap-2.5 rounded-lg border border-border bg-bg-elev p-3"
                    data-action-ai-reasoning
                >
                    <Sparkles class="mt-0.5 size-4 shrink-0 text-accent" />
                    <div class="flex flex-col gap-1">
                        <span
                            class="text-[11px] font-medium tracking-wide text-fg-subtle uppercase"
                            >AI reasoning</span
                        >
                        <span class="text-[12.5px] text-muted-foreground">{{
                            selected.agent_rationale
                        }}</span>
                    </div>
                </div>

                <SecondOpinionVerdict
                    v-if="selected.second_opinion"
                    :verdict="selected.second_opinion"
                />

                <Button
                    v-if="canBenchmark(selected)"
                    variant="outline"
                    size="sm"
                    class="w-fit"
                    as-child
                >
                    <Link
                        :href="
                            BenchmarkCaseController.create.url({
                                query: { action_request: selected.id },
                            })
                        "
                        data-action-benchmark-verdict
                        >Benchmark this second opinion</Link
                    >
                </Button>

                <template v-if="selected.type === 'replace_media_file'">
                    <template v-if="!selected.details?.length">
                        <Field label="Required subtitles">{{
                            replacementLanguages(selected)
                        }}</Field>
                        <Field label="Confidence">{{
                            replacementConfidence(selected)
                        }}</Field>
                        <Field label="Selection">{{
                            replacementSelectionMode(selected)
                        }}</Field>
                    </template>
                    <Field
                        label="Affected files"
                        data-replacement-affected-files
                        >{{ replacementAffectedFiles(selected) }}</Field
                    >
                    <Field label="Evidence" data-replacement-evidence>{{
                        replacementEvidence(selected)
                    }}</Field>
                    <Field label="Replacement attempt">
                        <template v-if="selected.replacement_attempt">
                            <div class="flex flex-wrap items-center gap-2">
                                <Pill
                                    :variant="
                                        attemptVariant(
                                            selected.replacement_attempt.status,
                                        )
                                    "
                                    dot
                                    data-replacement-attempt-status
                                >
                                    {{
                                        selected.replacement_attempt.status.replace(
                                            /_/g,
                                            ' ',
                                        )
                                    }}
                                </Pill>
                                <span
                                    v-if="
                                        selected.replacement_attempt
                                            .failure_reason
                                    "
                                    class="font-mono-tabular text-[11.5px] text-muted-foreground"
                                >
                                    {{
                                        selected.replacement_attempt
                                            .failure_reason
                                    }}
                                </span>
                                <Link
                                    v-if="isAdmin"
                                    :href="
                                        MediaReplacementAttemptController.show.url(
                                            selected.replacement_attempt.id,
                                        )
                                    "
                                    class="text-[12px] text-accent hover:underline"
                                    data-replacement-attempt-link
                                >
                                    View attempt
                                </Link>
                            </div>
                        </template>
                        <span v-else class="text-fg-subtle">not started</span>
                    </Field>
                    <div
                        v-if="replacementIsSeasonPack(selected)"
                        class="rounded-lg border border-warning/30 bg-warning/10 p-3 text-xs text-warning"
                    >
                        This replacement affects a season pack and may delete
                        multiple existing episode files.
                    </div>
                </template>

                <div
                    v-if="isDestructive(selected.type)"
                    class="flex gap-2.5 rounded-lg border border-destructive/25 bg-destructive/10 p-3"
                >
                    <AlertTriangle
                        class="mt-0.5 size-4 shrink-0 text-destructive"
                    />
                    <div class="text-[12.5px] text-muted-foreground">
                        This action is
                        <span class="font-semibold text-destructive"
                            >destructive</span
                        >. Approval queues an
                        <span class="font-mono-tabular text-[11.5px]"
                            >ExecuteActionRequest</span
                        >
                        job. Files on disk will be removed.
                    </div>
                </div>

                <ActionPipeline :request="selected" />

                <div
                    v-if="selected.status === 'pending'"
                    class="mt-1 flex gap-2"
                >
                    <Button
                        class="flex-1"
                        data-action-approve
                        @click="emit('approve', selected.id)"
                    >
                        Approve &amp; execute
                    </Button>
                    <Button
                        variant="destructive"
                        data-action-decline
                        @click="emit('reject', selected.id)"
                    >
                        Decline
                    </Button>
                </div>
                <Button
                    v-else-if="selected.status === 'failed' && isAdmin"
                    variant="outline"
                    data-action-retry
                    @click="emit('retry', selected)"
                >
                    Retry
                </Button>
            </div>
        </div>
    </aside>
</template>
