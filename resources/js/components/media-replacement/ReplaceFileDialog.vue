<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Loader2, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ActionRequestController from '@/actions/App/Http/Controllers/Actions/ActionRequestController';
import MediaReplacementController from '@/actions/App/Http/Controllers/Media/MediaReplacementController';
import { Toggle } from '@/components/mm';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { jsonRequest } from '@/composables/useAiChat';
import { cn } from '@/lib/utils';
import type { QueryParams } from '@/wayfinder';

// State machine: idle -> inspecting -> inspected -> searching -> picked -> submitting
type Phase =
    | 'idle'
    | 'inspecting'
    | 'inspected'
    | 'searching'
    | 'picked'
    | 'submitting';

interface ReplacementSnapshot {
    service: 'sonarr' | 'radarr';
    service_connection_id: number;
    display_name: string;
    scope: string | null;
    quality: string | null;
    size: number | null;
    subtitles: string[];
    date_added: string | null;
}

interface MatchedRule {
    name: string;
    strength: 'guarantee' | 'strong_evidence' | 'preference';
    languages: string[];
    evidences_subtitles: boolean;
    explanation?: string;
}

interface ReplacementCandidate {
    fingerprint: string;
    title: string;
    release_group: string;
    subgroup: string;
    quality: string | null;
    size: number;
    age: number;
    seeders: number;
    custom_format_score: number;
    confidence: number;
    requires_approval: boolean;
    rejection_reasons: string[];
    matched_rules: MatchedRule[];
    season_pack: boolean;
}

interface InspectResponse {
    snapshot: ReplacementSnapshot;
    fingerprint: string;
    required_languages: string[];
}

interface CandidatesResponse {
    candidates: ReplacementCandidate[];
    effective_languages: string[];
    excluded: Record<string, number>;
}

interface ReplaceResponse {
    action_request_id: number;
    action_queue_url: string;
    requires_approval: boolean;
}

const props = defineProps<{
    service: 'sonarr' | 'radarr';
    connectionId: number;
    itemId: number;
    seasonNumber?: number;
    episodeNumber?: number;
    open: boolean;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
}>();

const phase = ref<Phase>('idle');
const errorMessage = ref<string | null>(null);
const snapshot = ref<ReplacementSnapshot | null>(null);
const fingerprint = ref<string | null>(null);
const requiredLanguages = ref<string[]>([]);
const verifySubtitles = ref(false);
const candidates = ref<ReplacementCandidate[]>([]);
const searchedOnce = ref(false);
const selectedFingerprint = ref<string | null>(null);
const slowHint = ref(false);

let requestSeq = 0;
let slowTimer: ReturnType<typeof setTimeout> | null = null;

const isBusy = computed(
    () =>
        phase.value === 'inspecting' ||
        phase.value === 'searching' ||
        phase.value === 'submitting',
);

const selectedCandidate = computed(
    () =>
        candidates.value.find(
            (candidate) => candidate.fingerprint === selectedFingerprint.value,
        ) ?? null,
);

function targetQuery(): QueryParams {
    return {
        service: props.service,
        service_connection_id: props.connectionId,
        item_id: props.itemId,
        season_number: props.seasonNumber ?? null,
        episode_number: props.episodeNumber ?? null,
    };
}

function startSlowTimer(): void {
    stopSlowTimer();
    slowTimer = setTimeout(() => {
        slowHint.value = true;
    }, 10_000);
}

function stopSlowTimer(): void {
    if (slowTimer !== null) {
        clearTimeout(slowTimer);
        slowTimer = null;
    }

    slowHint.value = false;
}

function resetState(): void {
    requestSeq++;
    stopSlowTimer();
    phase.value = 'idle';
    errorMessage.value = null;
    snapshot.value = null;
    fingerprint.value = null;
    requiredLanguages.value = [];
    verifySubtitles.value = false;
    candidates.value = [];
    searchedOnce.value = false;
    selectedFingerprint.value = null;
}

function messageFrom(error: unknown, fallback: string): string {
    return error instanceof Error && error.message ? error.message : fallback;
}

async function runInspect(): Promise<void> {
    const seq = ++requestSeq;
    phase.value = 'inspecting';
    errorMessage.value = null;
    startSlowTimer();

    try {
        const data = await jsonRequest<InspectResponse>(
            'get',
            MediaReplacementController.inspect.url({ query: targetQuery() }),
        );

        if (seq !== requestSeq) {
            return;
        }

        snapshot.value = data.snapshot;
        fingerprint.value = data.fingerprint;
        requiredLanguages.value = data.required_languages;
        verifySubtitles.value = data.required_languages.length > 0;
        phase.value = 'inspected';
    } catch (error) {
        if (seq !== requestSeq) {
            return;
        }

        errorMessage.value = messageFrom(
            error,
            'Could not inspect this file.',
        );
        phase.value = 'idle';
    } finally {
        if (seq === requestSeq) {
            stopSlowTimer();
        }
    }
}

async function searchCandidates(): Promise<void> {
    if (fingerprint.value === null) {
        return;
    }

    const seq = ++requestSeq;
    phase.value = 'searching';
    errorMessage.value = null;
    selectedFingerprint.value = null;
    startSlowTimer();

    try {
        const data = await jsonRequest<CandidatesResponse>(
            'get',
            MediaReplacementController.candidates.url({ query: targetQuery() }),
        );

        if (seq !== requestSeq) {
            return;
        }

        candidates.value = data.candidates;
        searchedOnce.value = true;
        phase.value = 'inspected';
    } catch (error) {
        if (seq !== requestSeq) {
            return;
        }

        errorMessage.value = messageFrom(
            error,
            'Could not search for replacements.',
        );
        phase.value = 'inspected';
    } finally {
        if (seq === requestSeq) {
            stopSlowTimer();
        }
    }
}

function pickCandidate(candidateFingerprint: string): void {
    selectedFingerprint.value = candidateFingerprint;
    phase.value = 'picked';
}

async function submitReplacement(): Promise<void> {
    if (fingerprint.value === null || selectedFingerprint.value === null) {
        return;
    }

    const seq = ++requestSeq;
    phase.value = 'submitting';
    errorMessage.value = null;

    try {
        const data = await jsonRequest<ReplaceResponse>(
            'post',
            MediaReplacementController.replace.url(),
            {
                ...targetQuery(),
                target_fingerprint: fingerprint.value,
                candidate_fingerprint: selectedFingerprint.value,
                verify_subtitles: verifySubtitles.value,
            },
        );

        if (seq !== requestSeq) {
            return;
        }

        toast.success(
            data.requires_approval
                ? 'Replacement queued for approval'
                : 'Replacement queued',
            {
                action: {
                    label: 'Action Queue',
                    onClick: () => router.visit(ActionRequestController.index.url()),
                },
            },
        );
        emit('update:open', false);
    } catch (error) {
        if (seq !== requestSeq) {
            return;
        }

        errorMessage.value = messageFrom(
            error,
            'Could not queue the replacement.',
        );
        phase.value = 'picked';
    }
}

function formatSize(bytes: number | null): string {
    if (!bytes || bytes <= 0) {
        return '-';
    }

    const gb = bytes / 1024 ** 3;

    if (gb >= 1) {
        return `${gb.toFixed(1)} GB`;
    }

    return `${(bytes / 1024 ** 2).toFixed(0)} MB`;
}

function ruleBadgeVariant(
    strength: MatchedRule['strength'],
): 'success' | 'default' | 'secondary' {
    if (strength === 'guarantee') {
        return 'success';
    }

    return strength === 'strong_evidence' ? 'default' : 'secondary';
}

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            resetState();
            void runInspect();
        } else {
            resetState();
        }
    },
);
</script>

<template>
    <Dialog
        :open="open"
        @update:open="(value) => emit('update:open', value)"
    >
        <DialogContent
            class="max-w-2xl"
            data-replacement-dialog
        >
            <DialogHeader>
                <DialogTitle>Replace file</DialogTitle>
                <DialogDescription>
                    Search for an alternative release and queue a replacement
                    for approval.
                </DialogDescription>
            </DialogHeader>

            <div class="max-h-[65vh] space-y-4 overflow-y-auto pr-1">
                <div
                    v-if="phase === 'inspecting' && snapshot === null"
                    class="flex items-center gap-2 py-6 text-sm text-muted-foreground"
                >
                    <Loader2 class="size-4 animate-spin" />
                    Inspecting the installed file…
                </div>

                <div
                    v-if="errorMessage"
                    data-replacement-error
                    class="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    <span>{{ errorMessage }}</span>
                </div>

                <p
                    v-if="slowHint && isBusy"
                    class="text-xs text-muted-foreground"
                >
                    Still working — this can take a little longer than usual.
                </p>

                <div
                    v-if="snapshot"
                    data-replacement-current-file
                    class="space-y-2 rounded-lg border border-border bg-card p-4"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-[13px] font-semibold">
                            {{ snapshot.display_name }}
                        </h3>
                        <Badge variant="outline">{{ snapshot.quality ?? 'Unknown quality' }}</Badge>
                    </div>
                    <div
                        class="flex flex-wrap items-center gap-3 text-[12px] text-muted-foreground"
                    >
                        <span>{{ formatSize(snapshot.size) }}</span>
                        <span v-if="snapshot.subtitles.length">
                            Subtitles: {{ snapshot.subtitles.join(', ') }}
                        </span>
                    </div>
                </div>

                <div
                    v-if="snapshot"
                    class="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2"
                >
                    <div>
                        <p class="text-[13px] font-medium">Verify subtitles</p>
                        <p class="text-[12px] text-muted-foreground">
                            Require evidence of the languages this file needs
                            before queuing.
                        </p>
                    </div>
                    <Toggle
                        v-model="verifySubtitles"
                        data-replacement-verify-toggle
                        :disabled="isBusy"
                    />
                </div>

                <div v-if="snapshot" class="flex justify-end">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="isBusy"
                        data-replacement-search
                        @click="searchCandidates"
                    >
                        <Loader2
                            v-if="phase === 'searching'"
                            class="size-3.5 animate-spin"
                        />
                        <Search v-else class="size-3.5" />
                        {{
                            phase === 'searching'
                                ? 'Searching…'
                                : searchedOnce
                                  ? 'Search again'
                                  : 'Search for replacements'
                        }}
                    </Button>
                </div>

                <p
                    v-if="
                        searchedOnce &&
                        candidates.length === 0 &&
                        phase !== 'searching' &&
                        !errorMessage
                    "
                    data-replacement-empty
                    class="py-4 text-center text-[13px] text-muted-foreground"
                >
                    No suitable releases found.
                </p>

                <div v-if="candidates.length" class="space-y-2">
                    <label
                        v-for="candidate in candidates"
                        :key="candidate.fingerprint"
                        data-replacement-candidate-row
                        :class="
                            cn(
                                'flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2.5 transition-colors',
                                selectedFingerprint === candidate.fingerprint
                                    ? 'border-primary bg-accent/40'
                                    : 'border-border hover:bg-bg-hover',
                            )
                        "
                    >
                        <input
                            type="radio"
                            name="replacement-candidate"
                            class="mt-1 accent-primary"
                            :value="candidate.fingerprint"
                            :checked="
                                selectedFingerprint === candidate.fingerprint
                            "
                            :disabled="isBusy"
                            @change="pickCandidate(candidate.fingerprint)"
                        />
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <p
                                class="truncate text-[13px] font-medium"
                                :title="candidate.title"
                            >
                                {{ candidate.title }}
                            </p>
                            <div
                                class="flex flex-wrap items-center gap-1.5"
                            >
                                <Badge variant="secondary">
                                    Confidence {{ candidate.confidence }}%
                                </Badge>
                                <Badge
                                    v-if="candidate.season_pack"
                                    variant="destructive"
                                >
                                    Season pack
                                </Badge>
                                <Badge
                                    v-for="rule in candidate.matched_rules"
                                    :key="rule.name"
                                    :variant="ruleBadgeVariant(rule.strength)"
                                >
                                    {{ rule.name }}
                                </Badge>
                            </div>
                            <div
                                class="flex flex-wrap items-center gap-3 text-[12px] text-muted-foreground"
                            >
                                <span>{{ formatSize(candidate.size) }}</span>
                                <span v-if="candidate.seeders">
                                    {{ candidate.seeders }} seeders
                                </span>
                                <span v-if="candidate.quality">
                                    {{ candidate.quality }}
                                </span>
                            </div>
                        </div>
                    </label>
                </div>
            </div>

            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="phase === 'submitting'"
                    @click="emit('update:open', false)"
                >
                    Cancel
                </Button>
                <Button
                    :disabled="!selectedCandidate || isBusy"
                    data-replacement-submit
                    @click="submitReplacement"
                >
                    {{
                        phase === 'submitting'
                            ? 'Queuing…'
                            : 'Queue replacement'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
