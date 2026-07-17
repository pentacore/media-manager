<script setup lang="ts">
import { useHttp, usePage } from '@inertiajs/vue3';
import { Download, Search, Sparkles, Upload } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import OperationController from '@/actions/App/Http/Controllers/Bazarr/OperationController';
import SearchController from '@/actions/App/Http/Controllers/Bazarr/SearchController';
import UploadController from '@/actions/App/Http/Controllers/Bazarr/UploadController';
import CandidateTable from '@/components/bazarr/CandidateTable.vue';
import OperationDialog from '@/components/bazarr/OperationDialog.vue';
import SubtitleTrackList from '@/components/bazarr/SubtitleTrackList.vue';
import type { SubtitleTrack } from '@/components/bazarr/SubtitleTrackList.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { SubtitleCandidateResource } from '@/typefinder/resources/SubtitleCandidateResource';
import type { SubtitleItemResource } from '@/typefinder/resources/SubtitleItemResource';

type OperationName =
    | 'download_best'
    | 'download_exact'
    | 'delete_subtitle'
    | 'sync_subtitle'
    | 'translate_subtitle'
    | 'modify_subtitle'
    | 'scan_media';

interface SearchResponse {
    item: SubtitleItemResource;
    history: Array<{
        action: number | null;
        language: string;
        provider: string | null;
        occurred_at: string;
    }>;
    candidates: SubtitleCandidateResource[];
    capabilities: Record<string, boolean>;
}

interface OperationResponse {
    id: number;
    type: string;
    status: string;
    message: string;
}

interface OperationForm {
    operation: string;
    connection: number | null;
    media_type: string;
    media_id: number | null;
    target_fingerprint: string;
    language: string | null;
    forced: boolean | null;
    hearing_impaired: boolean | null;
    candidate_fingerprint: string | null;
    subtitle_fingerprint: string | null;
    tool_action: string | null;
    media_action: string | null;
}

interface UploadForm {
    connection: number | null;
    media_type: string;
    media_id: number | null;
    target_fingerprint: string;
    language: string;
    forced: boolean;
    hearing_impaired: boolean;
    subtitle_file: File | null;
}

const props = defineProps<{
    open: boolean;
    item: SubtitleItemResource | null;
    connectionId: number | null;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
}>();

const page = usePage();
const canOperate = computed(() => {
    const role = page.props.auth.user?.role;
    const value = typeof role === 'string' ? role : role?.value;

    return value === 'member' || value === 'admin';
});

const inspectedItem = ref<SubtitleItemResource | null>(null);
const candidates = ref<SubtitleCandidateResource[]>([]);
const history = ref<SearchResponse['history']>([]);
const capabilities = ref<Record<string, boolean> | null>(null);
const selectedOperation = ref<{
    operation: OperationName;
    track?: SubtitleTrack;
    tool_action?: string;
} | null>(null);
const uploadOpen = ref(false);
const selectedUpload = ref<File | null>(null);
const uploadLanguage = ref('eng');

const searchHttp = useHttp<
    {
        connection: number | null;
        media_type: string;
        media_id: number | null;
        target_fingerprint: string;
    },
    SearchResponse
>({
    connection: null,
    media_type: '',
    media_id: null,
    target_fingerprint: '',
});

const operationHttp = useHttp<OperationForm, OperationResponse>({
    operation: '',
    connection: null,
    media_type: '',
    media_id: null,
    target_fingerprint: '',
    language: null,
    forced: null,
    hearing_impaired: null,
    candidate_fingerprint: null,
    subtitle_fingerprint: null,
    tool_action: null,
    media_action: null,
});

const uploadHttp = useHttp<UploadForm, OperationResponse>({
    connection: null,
    media_type: '',
    media_id: null,
    target_fingerprint: '',
    language: 'eng',
    forced: false,
    hearing_impaired: false,
    subtitle_file: null,
});

const currentItem = computed(() => inspectedItem.value ?? props.item);
const currentTracks = computed(
    () => (currentItem.value?.subtitle_tracks ?? []) as SubtitleTrack[],
);
const preferredLanguage = computed(
    () =>
        currentItem.value?.missing_languages?.[0] ??
        currentItem.value?.required_languages?.[0] ??
        'eng',
);

watch(
    () => [props.open, props.item?.media_id, props.item?.media_type],
    () => {
        inspectedItem.value = null;
        candidates.value = [];
        history.value = [];
        capabilities.value = null;
        selectedOperation.value = null;
        uploadOpen.value = false;
        selectedUpload.value = null;
        uploadHttp.reset();
    },
);

function search(): void {
    if (!props.item || !props.connectionId) {
        return;
    }

    searchHttp.connection = props.connectionId;
    searchHttp.media_type = props.item.media_type;
    searchHttp.media_id = props.item.media_id;
    searchHttp.target_fingerprint = props.item.target_fingerprint;
    searchHttp.get(SearchController.url(), {
        onSuccess: (response) => {
            inspectedItem.value = response.item;
            candidates.value = response.candidates;
            history.value = response.history;
            capabilities.value = response.capabilities;
        },
        onError: () => toast.error('Could not search Bazarr right now.'),
    });
}

function basePayload(operation: OperationName): Record<string, unknown> | null {
    const item = currentItem.value;

    if (!item || !props.connectionId) {
        return null;
    }

    return {
        operation,
        connection: props.connectionId,
        media_type: item.media_type,
        media_id: item.media_id,
        target_fingerprint: item.target_fingerprint,
    };
}

function submit(
    operation: OperationName,
    fields: Record<string, unknown> = {},
): void {
    const payload = basePayload(operation);

    if (!payload) {
        return;
    }

    operationHttp.reset();
    Object.assign(operationHttp, payload, fields);
    operationHttp.post(OperationController.url(), {
        onSuccess: (response) => {
            selectedOperation.value = null;
            toast.success(response.message);
        },
        onError: () =>
            toast.error('The subtitle operation could not be requested.'),
    });
}

function requestBest(): void {
    submit('download_best', {
        language: preferredLanguage.value,
        forced: false,
        hearing_impaired: false,
    });
}

function requestCandidate(candidate: SubtitleCandidateResource): void {
    submit('download_exact', {
        candidate_fingerprint: candidate.fingerprint,
    });
}

function openUpload(): void {
    uploadHttp.resetAndClearErrors();
    selectedUpload.value = null;
    uploadLanguage.value = preferredLanguage.value;
    uploadOpen.value = true;
}

function selectUpload(event: Event): void {
    const input = event.target as HTMLInputElement;
    selectedUpload.value = input.files?.[0] ?? null;
}

function submitUpload(): void {
    const item = currentItem.value;

    if (!item || !props.connectionId || !selectedUpload.value) {
        return;
    }

    const payload: UploadForm = {
        connection: props.connectionId,
        media_type: item.media_type,
        media_id: item.media_id,
        target_fingerprint: item.target_fingerprint,
        language: uploadLanguage.value.trim().toLowerCase(),
        forced: false,
        hearing_impaired: false,
        subtitle_file: selectedUpload.value,
    };
    Object.assign(uploadHttp, payload);
    uploadHttp.transform(() => payload);
    uploadHttp.post(UploadController.url(), {
        onSuccess: (response) => {
            uploadOpen.value = false;
            selectedUpload.value = null;
            toast.success(response.message);
        },
        onError: () =>
            toast.error('The subtitle upload could not be requested.'),
    });
}

function openTrackOperation(payload: {
    operation:
        | 'delete_subtitle'
        | 'sync_subtitle'
        | 'translate_subtitle'
        | 'modify_subtitle';
    track: SubtitleTrack;
    tool_action?: string;
}): void {
    selectedOperation.value = payload;
}

function confirmTrackOperation(): void {
    if (!selectedOperation.value?.track) {
        return;
    }

    submit(selectedOperation.value.operation, {
        subtitle_fingerprint: selectedOperation.value.track.fingerprint,
        ...(selectedOperation.value.tool_action
            ? { tool_action: selectedOperation.value.tool_action }
            : {}),
    });
}

const dialogTitle = computed(() => {
    const operation = selectedOperation.value?.operation;

    switch (operation) {
        case 'delete_subtitle':
            return 'Delete subtitle file?';
        case 'sync_subtitle':
            return 'Synchronize subtitle?';
        case 'translate_subtitle':
            return 'Translate subtitle?';
        case 'modify_subtitle':
            return 'Modify subtitle?';
        default:
            return 'Request subtitle operation';
    }
});

const dialogDescription = computed(
    () =>
        `${selectedOperation.value?.track?.display_name ?? 'This subtitle'} will be changed by Bazarr after the Action Request is approved.`,
);
</script>

<template>
    <Sheet :open="open" @update:open="emit('update:open', $event)">
        <SheetContent class="w-full overflow-y-auto sm:max-w-2xl">
            <SheetHeader>
                <SheetTitle>{{
                    currentItem?.title ?? 'Subtitle item'
                }}</SheetTitle>
                <SheetDescription>
                    Inspect tracks, search providers, and queue an
                    approval-gated operation.
                </SheetDescription>
            </SheetHeader>

            <div v-if="currentItem" class="space-y-6 px-1 pb-8">
                <section class="space-y-3">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h3 class="font-semibold">Current tracks</h3>
                        <span class="text-xs text-muted-foreground capitalize">
                            {{ currentItem.media_type }}
                            · {{ currentItem.scope ?? 'movie' }}
                        </span>
                    </div>
                    <SubtitleTrackList
                        :tracks="currentTracks"
                        :capabilities="capabilities"
                        :can-operate="canOperate"
                        @operate="openTrackOperation"
                    />
                </section>

                <section v-if="canOperate" class="space-y-3">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h3 class="font-semibold">Find subtitles</h3>
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="
                                searchHttp.processing ||
                                capabilities?.manual_search === false
                            "
                            data-test="subtitle-search"
                            @click="search"
                        >
                            <Search />
                            {{
                                searchHttp.processing
                                    ? 'Searching…'
                                    : 'Search Bazarr'
                            }}
                        </Button>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button
                            size="sm"
                            :disabled="
                                operationHttp.processing ||
                                capabilities?.best_download === false
                            "
                            data-test="subtitle-request-best"
                            @click="requestBest"
                        >
                            <Sparkles />
                            Request best {{ preferredLanguage.toUpperCase() }}
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="
                                operationHttp.processing ||
                                uploadHttp.processing ||
                                capabilities?.upload === false
                            "
                            data-test="subtitle-upload-open"
                            @click="openUpload"
                        >
                            <Upload />
                            Upload file
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="
                                operationHttp.processing ||
                                capabilities?.inventory === false
                            "
                            @click="
                                submit('scan_media', {
                                    media_action: 'scan-disk',
                                })
                            "
                        >
                            <Download />
                            Scan media
                        </Button>
                    </div>

                    <CandidateTable
                        v-if="candidates.length || !searchHttp.processing"
                        :candidates="candidates"
                        :disabled="
                            operationHttp.processing ||
                            capabilities?.exact_download === false
                        "
                        @request="requestCandidate"
                    />
                </section>

                <section v-if="history.length" class="space-y-2">
                    <h3 class="font-semibold">Recent history</h3>
                    <ul class="space-y-1 text-sm text-muted-foreground">
                        <li
                            v-for="(entry, index) in history.slice(0, 5)"
                            :key="`${entry.occurred_at}-${index}`"
                        >
                            {{ entry.language.toUpperCase() }}
                            <template v-if="entry.provider">
                                via {{ entry.provider }}
                            </template>
                        </li>
                    </ul>
                </section>
            </div>
        </SheetContent>
    </Sheet>

    <OperationDialog
        :open="selectedOperation !== null"
        :title="dialogTitle"
        :description="dialogDescription"
        :confirm-label="
            selectedOperation?.operation === 'delete_subtitle'
                ? 'Request deletion'
                : 'Request operation'
        "
        :destructive="selectedOperation?.operation === 'delete_subtitle'"
        :processing="operationHttp.processing"
        @update:open="
            (value) => {
                if (!value) selectedOperation = null;
            }
        "
        @confirm="confirmTrackOperation"
    />

    <OperationDialog
        :open="uploadOpen"
        title="Upload subtitle file"
        description="The file is staged privately and sent to Bazarr only after the Action Request is approved."
        confirm-label="Request upload"
        :processing="uploadHttp.processing"
        @update:open="uploadOpen = $event"
        @confirm="submitUpload"
    >
        <div class="space-y-4">
            <div class="space-y-2">
                <label class="text-sm font-medium" for="subtitle-upload-file">
                    Subtitle file
                </label>
                <Input
                    id="subtitle-upload-file"
                    name="subtitle_file"
                    type="file"
                    accept=".srt,.ass,.ssa,.vtt,.sub"
                    data-test="subtitle-upload-file"
                    @change="selectUpload"
                />
                <p
                    v-if="uploadHttp.errors.subtitle_file"
                    class="text-sm text-destructive"
                >
                    {{ uploadHttp.errors.subtitle_file }}
                </p>
                <p v-else class="text-xs text-muted-foreground">
                    SRT, ASS, SSA, VTT, or SUB. Maximum 5 MB.
                </p>
            </div>
            <div class="space-y-2">
                <label
                    class="text-sm font-medium"
                    for="subtitle-upload-language"
                >
                    Language code
                </label>
                <Input
                    id="subtitle-upload-language"
                    v-model="uploadLanguage"
                    name="language"
                    maxlength="24"
                    data-test="subtitle-upload-language"
                />
                <p
                    v-if="uploadHttp.errors.language"
                    class="text-sm text-destructive"
                >
                    {{ uploadHttp.errors.language }}
                </p>
            </div>
        </div>
    </OperationDialog>
</template>
