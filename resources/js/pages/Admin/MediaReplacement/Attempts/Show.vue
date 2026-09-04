<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, ExternalLink } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import ActionRequestController from '@/actions/App/Http/Controllers/Actions/ActionRequestController';
import MediaReplacementAttemptController from '@/actions/App/Http/Controllers/Admin/MediaReplacementAttemptController';
import MediaReplacementSettingsController from '@/actions/App/Http/Controllers/Admin/MediaReplacementSettingsController';
import { Field, Pill, SvcChip, TimeStamp } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useRealtimeReload } from '@/composables/useRealtimeReload';
import { dashboard } from '@/routes';

interface AttemptDetail {
    id: number;
    action_request_id: number;
    status: string;
    failure_reason: string | null;
    scope: string;
    service: { id: number; name: string; type: string } | null;
    display_name: string | null;
    season_number: number | null;
    episode_numbers: number[];
    timeline: {
        created_at: string | null;
        started_at: string | null;
        grab_attempted_at: string | null;
        grab_accepted_at: string | null;
        cleanup_completed_at: string | null;
        completed_at: string | null;
    };
    download_id: string | null;
    target: Record<string, unknown>;
    candidate: Record<string, unknown>;
    verification: Record<string, unknown> | null;
    required_languages: string[];
    monitoring: {
        was_monitored: boolean | null;
        monitoring_suspended: boolean | null;
    };
    acknowledged: { at: string; by_name: string | null } | null;
    action_request: { id: number; status: string | null };
    links: {
        media: string | null;
        action_request: string;
        grab_queue: string | null;
    };
    can: {
        retry: boolean;
        restore_monitoring: boolean;
        acknowledge: boolean;
        cancel: boolean;
    };
}

const props = defineProps<{ attempt: AttemptDetail }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            {
                title: 'Media Replacement',
                href: MediaReplacementSettingsController.index.url(),
            },
            {
                title: 'Attempts',
                href: MediaReplacementAttemptController.index.url(),
            },
        ],
    },
});

type PillVariant = 'default' | 'ok' | 'warn' | 'danger' | 'info';

const STATUS_VARIANTS: Record<string, PillVariant> = {
    requested: 'info',
    downloading: 'info',
    imported: 'default',
    verified: 'ok',
    failed: 'danger',
    needs_attention: 'warn',
};

function statusVariant(status: string): PillVariant {
    return STATUS_VARIANTS[status] ?? 'default';
}

function humanize(value: string): string {
    return value.replace(/_/g, ' ');
}

function text(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (Array.isArray(value)) {
        return value.length ? value.map(String).join(', ') : '—';
    }

    if (typeof value === 'object') {
        const named = value as Record<string, unknown>;

        if (typeof named['name'] === 'string') {
            return named['name'];
        }

        return JSON.stringify(value);
    }

    return String(value);
}

function bytes(value: unknown): string {
    if (typeof value !== 'number' || value <= 0) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = value;
    let unit = 0;

    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit += 1;
    }

    return `${size.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

function json(value: unknown): string {
    return JSON.stringify(value, null, 2);
}

const TIMELINE: { key: keyof AttemptDetail['timeline']; label: string }[] = [
    { key: 'created_at', label: 'Requested' },
    { key: 'started_at', label: 'Execution started' },
    { key: 'grab_attempted_at', label: 'Grab attempted' },
    { key: 'grab_accepted_at', label: 'Grab accepted' },
    { key: 'cleanup_completed_at', label: 'Cleanup completed' },
    { key: 'completed_at', label: 'Completed' },
];

const busy = ref(false);
const cancelOpen = ref(false);

function post(url: string): void {
    if (busy.value) {
        return;
    }

    busy.value = true;
    router.post(
        url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
                cancelOpen.value = false;
            },
        },
    );
}

function retry(): void {
    post(ActionRequestController.retry.url(props.attempt.action_request_id));
}

function restoreMonitoring(): void {
    post(
        MediaReplacementAttemptController.restoreMonitoring.url(
            props.attempt.id,
        ),
    );
}

function acknowledge(): void {
    post(MediaReplacementAttemptController.acknowledge.url(props.attempt.id));
}

function confirmCancel(): void {
    post(MediaReplacementAttemptController.cancel.url(props.attempt.id));
}

const { subscribe } = useRealtimeReload<{ id: number }>({
    channel: 'admin.media-replacement',
    event: 'MediaReplacementAttemptChanged',
    only: ['attempt'],
    filter: (event) => event.id === props.attempt.id,
});

onMounted(subscribe);
</script>

<template>
    <Head :title="`Attempt #${attempt.id}`" />

    <div class="flex flex-col gap-4 p-5">
        <Link
            :href="MediaReplacementAttemptController.index.url()"
            class="inline-flex items-center gap-1 text-[13px] text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-3.5" /> All attempts
        </Link>

        <div
            data-attempt-header
            class="flex flex-wrap items-start justify-between gap-3"
        >
            <div>
                <div class="mb-1.5 text-[13px] text-muted-foreground">
                    Attempt
                    <span class="font-mono-tabular">#{{ attempt.id }}</span>
                    <span class="text-fg-subtle">/</span>
                    request
                    <span class="font-mono-tabular"
                        >act_{{ attempt.action_request_id }}</span
                    >
                </div>
                <h1
                    class="flex flex-wrap items-center gap-2 text-[22px] leading-tight font-semibold tracking-tight"
                >
                    <SvcChip
                        v-if="attempt.service"
                        :id="attempt.service.type"
                    />
                    {{ attempt.display_name ?? 'Media file' }}
                    <Pill :variant="statusVariant(attempt.status)" dot>
                        {{ humanize(attempt.status) }}
                    </Pill>
                    <Pill variant="default">{{ attempt.scope }}</Pill>
                </h1>
                <p
                    v-if="attempt.failure_reason"
                    class="font-mono-tabular mt-1 text-[12.5px] text-muted-foreground"
                >
                    {{ attempt.failure_reason }}
                </p>
                <p
                    v-if="attempt.acknowledged"
                    class="mt-1 text-[12.5px] text-fg-subtle"
                >
                    Acknowledged by
                    {{ attempt.acknowledged.by_name ?? 'an admin' }}
                    <TimeStamp :iso="attempt.acknowledged.at" />
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="attempt.can.retry"
                    size="sm"
                    class="h-8 text-xs"
                    data-action="retry"
                    :disabled="busy"
                    @click="retry"
                >
                    Retry
                </Button>
                <Button
                    v-if="attempt.can.restore_monitoring"
                    size="sm"
                    variant="outline"
                    class="h-8 text-xs"
                    data-action="restore-monitoring"
                    :disabled="busy"
                    @click="restoreMonitoring"
                >
                    Restore monitoring
                </Button>
                <Button
                    v-if="attempt.can.acknowledge"
                    size="sm"
                    variant="outline"
                    class="h-8 text-xs"
                    data-action="acknowledge"
                    :disabled="busy"
                    @click="acknowledge"
                >
                    Acknowledge
                </Button>
                <Button
                    v-if="attempt.can.cancel"
                    size="sm"
                    variant="destructive"
                    class="h-8 text-xs"
                    data-action="cancel"
                    :disabled="busy"
                    @click="cancelOpen = true"
                >
                    Cancel download
                </Button>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section
                data-attempt-timeline
                class="rounded-xl border border-border bg-card p-4"
            >
                <h2 class="mb-3 text-[13px] font-semibold">Timeline</h2>
                <dl class="grid gap-2">
                    <div
                        v-for="step in TIMELINE"
                        :key="step.key"
                        class="flex items-center justify-between gap-3 text-[12.5px]"
                    >
                        <dt class="text-muted-foreground">{{ step.label }}</dt>
                        <dd class="font-mono-tabular text-right">
                            <TimeStamp
                                v-if="attempt.timeline[step.key]"
                                :iso="attempt.timeline[step.key]"
                                mode="datetime"
                            />
                            <span v-else class="text-fg-subtle">—</span>
                        </dd>
                    </div>
                </dl>
                <div class="mt-3 border-t border-border pt-3">
                    <Field label="Download id">
                        <span
                            class="font-mono-tabular text-[12px] break-all select-all"
                            >{{ attempt.download_id ?? '—' }}</span
                        >
                    </Field>
                </div>
            </section>

            <section
                data-attempt-monitoring
                class="rounded-xl border border-border bg-card p-4"
            >
                <h2 class="mb-3 text-[13px] font-semibold">
                    Monitoring &amp; links
                </h2>
                <div class="grid gap-3">
                    <Field label="Monitored before replacement">{{
                        attempt.monitoring.was_monitored === null
                            ? '—'
                            : attempt.monitoring.was_monitored
                              ? 'yes'
                              : 'no'
                    }}</Field>
                    <Field label="Suspension">
                        <span
                            v-if="attempt.monitoring.monitoring_suspended"
                            class="text-warning"
                            >still suspended</span
                        >
                        <span v-else>{{
                            attempt.monitoring.monitoring_suspended === null
                                ? '—'
                                : 'lifted'
                        }}</span>
                    </Field>
                    <Field label="Action request">
                        <Link
                            :href="attempt.links.action_request"
                            class="inline-flex items-center gap-1 text-accent hover:underline"
                        >
                            act_{{ attempt.action_request.id }}
                            <span class="text-muted-foreground">
                                · {{ attempt.action_request.status ?? '—' }}
                            </span>
                        </Link>
                    </Field>
                    <Field label="Open">
                        <div class="flex flex-wrap gap-2">
                            <Link
                                v-if="attempt.links.media"
                                :href="attempt.links.media"
                                class="inline-flex items-center gap-1 text-accent hover:underline"
                            >
                                <ExternalLink class="size-3" /> Media page
                            </Link>
                            <Link
                                v-if="attempt.links.grab_queue"
                                :href="attempt.links.grab_queue"
                                class="inline-flex items-center gap-1 text-accent hover:underline"
                            >
                                <ExternalLink class="size-3" /> Grab queue
                            </Link>
                            <span
                                v-if="
                                    !attempt.links.media &&
                                    !attempt.links.grab_queue
                                "
                                class="text-fg-subtle"
                                >—</span
                            >
                        </div>
                    </Field>
                </div>
            </section>

            <section
                data-attempt-target
                class="rounded-xl border border-border bg-card p-4"
            >
                <h2 class="mb-3 text-[13px] font-semibold">Target (before)</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <Field label="Installed release">{{
                        text(attempt.target['installed_release'])
                    }}</Field>
                    <Field label="Release group">{{
                        text(attempt.target['release_group'])
                    }}</Field>
                    <Field label="Quality">{{
                        text(attempt.target['quality'])
                    }}</Field>
                    <Field label="Size">{{
                        bytes(attempt.target['size'])
                    }}</Field>
                    <Field label="Added">{{
                        text(attempt.target['date_added'])
                    }}</Field>
                    <Field label="Subtitles present">{{
                        text(attempt.target['subtitles'])
                    }}</Field>
                    <Field label="File ids">{{
                        text(
                            attempt.target['episode_file_ids'] ??
                                attempt.target['movie_file_ids'],
                        )
                    }}</Field>
                    <Field label="Required languages">{{
                        text(attempt.required_languages)
                    }}</Field>
                </div>
            </section>

            <section
                data-attempt-candidate
                class="rounded-xl border border-border bg-card p-4"
            >
                <h2 class="mb-3 text-[13px] font-semibold">Chosen release</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <Field label="Title">
                        <span class="font-mono-tabular text-[12px] break-all">{{
                            text(attempt.candidate['title'])
                        }}</span>
                    </Field>
                    <Field label="Release group">{{
                        text(
                            attempt.candidate['release_group'] ??
                                attempt.candidate['subgroup'],
                        )
                    }}</Field>
                    <Field label="Quality">{{
                        text(attempt.candidate['quality'])
                    }}</Field>
                    <Field label="Size">{{
                        bytes(attempt.candidate['size'])
                    }}</Field>
                    <Field label="Confidence">{{
                        typeof attempt.candidate['confidence'] === 'number'
                            ? `${attempt.candidate['confidence']}%`
                            : '—'
                    }}</Field>
                    <Field label="Seeders / age">{{
                        `${text(attempt.candidate['seeders'])} / ${text(attempt.candidate['age'])}`
                    }}</Field>
                    <Field label="Matched rules">{{
                        text(attempt.candidate['matched_rules'])
                    }}</Field>
                    <Field label="Rejections">{{
                        text(attempt.candidate['rejection_reasons'])
                    }}</Field>
                </div>
                <div
                    v-if="attempt.candidate['season_pack'] === true"
                    class="mt-3 rounded-lg border border-warning/30 bg-warning/10 p-3 text-xs text-warning"
                >
                    Season pack — this replacement covered several episode
                    files.
                </div>
            </section>

            <section
                data-attempt-verification
                class="rounded-xl border border-border bg-card p-4 lg:col-span-2"
            >
                <h2 class="mb-3 text-[13px] font-semibold">Verification</h2>
                <p
                    v-if="!attempt.verification"
                    class="text-[12.5px] text-fg-subtle"
                >
                    Not verified yet.
                </p>
                <div v-else class="grid gap-3 sm:grid-cols-4">
                    <Field label="Subtitles checked">{{
                        attempt.verification['subtitles_checked'] === false
                            ? 'skipped by request'
                            : 'yes'
                    }}</Field>
                    <Field label="Required">{{
                        text(attempt.verification['required'])
                    }}</Field>
                    <Field label="Found">{{
                        text(attempt.verification['found'])
                    }}</Field>
                    <Field label="Missing">
                        <span
                            :class="
                                Array.isArray(
                                    attempt.verification['missing'],
                                ) && attempt.verification['missing'].length
                                    ? 'text-destructive'
                                    : ''
                            "
                            >{{ text(attempt.verification['missing']) }}</span
                        >
                    </Field>
                </div>
            </section>
        </div>

        <details class="rounded-xl border border-border bg-card p-4">
            <summary class="cursor-pointer text-[13px] font-semibold">
                Raw JSON
            </summary>
            <div class="mt-3 grid gap-3 lg:grid-cols-3">
                <div
                    v-for="(value, key) in {
                        target: attempt.target,
                        candidate: attempt.candidate,
                        verification: attempt.verification,
                    }"
                    :key="key"
                >
                    <div
                        class="mb-1 text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                    >
                        {{ key }}
                    </div>
                    <pre
                        class="font-mono-tabular max-h-[420px] overflow-auto rounded-lg border border-border bg-background p-3 text-[11px] leading-relaxed"
                        >{{ json(value) }}</pre>
                </div>
            </div>
        </details>

        <Dialog v-if="attempt.can.cancel" v-model:open="cancelOpen">
            <DialogContent class="max-w-lg">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <AlertTriangle class="size-4 text-destructive" />
                        Cancel this replacement?
                    </DialogTitle>
                    <DialogDescription>
                        The original file has already been deleted. Cancelling
                        removes our download from the download client, restores
                        monitoring, and marks the attempt failed. The target
                        stays without a file until the service searches again.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="cancelOpen = false"
                    >
                        Keep waiting
                    </Button>
                    <Button
                        variant="destructive"
                        size="sm"
                        data-action="cancel-confirm"
                        :disabled="busy"
                        @click="confirmCancel"
                    >
                        Cancel download
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
