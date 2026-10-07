<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AlertTriangle, ArrowDown, ArrowUp, Loader2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ActionRequestController from '@/actions/App/Http/Controllers/Actions/ActionRequestController';
import MediaActionController from '@/actions/App/Http/Controllers/Library/MediaActionController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { jsonRequest } from '@/composables/useAiChat';
import { formatBytes } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ReleaseRow } from '@/types';
import type { QueryParams } from '@/wayfinder';

type SortKey = 'title' | 'quality' | 'size' | 'age_hours' | 'peers' | 'indexer';

interface GrabResponse {
    action_request_id: number;
    requires_approval: boolean;
    message: string;
}

const props = withDefaults(
    defineProps<{
        open: boolean;
        service: 'sonarr' | 'radarr';
        connectionId: number;
        itemId: number;
        seasonNumber?: number | null;
        episodeId?: number | null;
        heading: string;
    }>(),
    { seasonNumber: null, episodeId: null },
);

const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const COLUMNS: { key: SortKey; label: string }[] = [
    { key: 'title', label: 'Release' },
    { key: 'quality', label: 'Quality' },
    { key: 'size', label: 'Size' },
    { key: 'age_hours', label: 'Age' },
    { key: 'peers', label: 'Peers' },
    { key: 'indexer', label: 'Indexer' },
];

const releases = ref<ReleaseRow[]>([]);
const loading = ref(false);
const errorMessage = ref<string | null>(null);
const slowHint = ref(false);
const grabbing = ref<string | null>(null);
const sortKey = ref<SortKey>('size');
const sortDescending = ref(true);
let requestSeq = 0;
let slowTimer: ReturnType<typeof setTimeout> | null = null;

function query(): QueryParams {
    return {
        service: props.service,
        service_connection_id: props.connectionId,
        item_id: props.itemId,
        season_number: props.seasonNumber,
        episode_id: props.episodeId,
    };
}

function sortValue(row: ReleaseRow, key: SortKey): string | number | null {
    switch (key) {
        case 'size':
            return row.size;
        case 'age_hours':
            return row.age_hours;
        case 'peers':
            return row.peers;
        case 'quality':
            return row.quality?.toLowerCase() ?? null;
        case 'indexer':
            return row.indexer?.toLowerCase() ?? null;
        default:
            return row.title.toLowerCase();
    }
}

const sorted = computed(() =>
    [...releases.value].sort((a, b) => {
        const av = sortValue(a, sortKey.value);
        const bv = sortValue(b, sortKey.value);

        if (av === null || bv === null) {
            return av === bv ? 0 : av === null ? 1 : -1;
        }

        const order =
            typeof av === 'number' && typeof bv === 'number'
                ? av - bv
                : String(av).localeCompare(String(bv));

        return sortDescending.value ? -order : order;
    }),
);

function sortBy(key: SortKey): void {
    if (sortKey.value === key) {
        sortDescending.value = !sortDescending.value;

        return;
    }

    sortKey.value = key;
    sortDescending.value =
        key !== 'title' && key !== 'indexer' && key !== 'quality';
}

function stopSlowTimer(): void {
    if (slowTimer !== null) {
        clearTimeout(slowTimer);
        slowTimer = null;
    }

    slowHint.value = false;
}

async function search(): Promise<void> {
    const seq = ++requestSeq;
    loading.value = true;
    errorMessage.value = null;
    releases.value = [];
    slowTimer = setTimeout(() => {
        slowHint.value = true;
    }, 10_000);

    try {
        const data = await jsonRequest<{ releases: ReleaseRow[] }>(
            'get',
            MediaActionController.releases.url({ query: query() }),
        );

        if (seq === requestSeq) {
            releases.value = data.releases;
        }
    } catch (error) {
        if (seq === requestSeq) {
            errorMessage.value =
                error instanceof Error
                    ? error.message
                    : 'Could not search for releases.';
        }
    } finally {
        if (seq === requestSeq) {
            loading.value = false;
            stopSlowTimer();
        }
    }
}

async function grab(row: ReleaseRow): Promise<void> {
    if (grabbing.value !== null) {
        return;
    }

    grabbing.value = row.key;
    errorMessage.value = null;

    try {
        const data = await jsonRequest<GrabResponse>(
            'post',
            MediaActionController.grab.url(),
            {
                service: props.service,
                service_connection_id: props.connectionId,
                item_id: props.itemId,
                release_key: row.key,
                indexer_id: row.indexer_id,
            },
        );

        const notify = data.requires_approval ? toast.info : toast.success;
        notify(
            data.message,
            data.requires_approval
                ? {
                      action: {
                          label: 'Action Queue',
                          onClick: () =>
                              router.visit(ActionRequestController.index.url()),
                      },
                  }
                : undefined,
        );
        emit('update:open', false);
    } catch (error) {
        errorMessage.value =
            error instanceof Error
                ? error.message
                : 'Could not grab this release.';
    } finally {
        grabbing.value = null;
    }
}

function formatAge(hours: number | null): string {
    if (hours === null) {
        return '—';
    }

    if (hours < 1) {
        return '<1h';
    }

    return hours < 48 ? `${Math.round(hours)}h` : `${Math.round(hours / 24)}d`;
}

watch(
    () => props.open,
    (isOpen) => {
        requestSeq++;
        stopSlowTimer();
        releases.value = [];
        errorMessage.value = null;
        grabbing.value = null;

        if (isOpen) {
            void search();
        }
    },
    { immediate: true },
);
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent
            class="max-h-[85vh] overflow-y-auto sm:max-w-5xl"
            data-interactive-search-dialog
        >
            <DialogHeader>
                <DialogTitle>Interactive search — {{ heading }}</DialogTitle>
                <DialogDescription>
                    Pick a release to send to the download client. Rejected
                    releases are dimmed; you can still grab them.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="loading"
                class="flex flex-col items-center gap-2 py-10 text-[13px] text-muted-foreground"
            >
                <Loader2 class="size-5 animate-spin" />
                Searching indexers…
                <span v-if="slowHint"
                    >Indexer searches can take up to two minutes.</span
                >
            </div>

            <p v-else-if="errorMessage" class="text-[13px] text-destructive">
                {{ errorMessage }}
            </p>

            <p
                v-else-if="releases.length === 0"
                class="py-6 text-center text-[13px] text-muted-foreground"
            >
                No releases found.
            </p>

            <table v-else class="w-full text-left text-[12.5px]">
                <thead
                    class="text-[11px] tracking-[0.05em] text-muted-foreground uppercase"
                >
                    <tr>
                        <th
                            v-for="column in COLUMNS"
                            :key="column.key"
                            class="px-2 py-1.5"
                        >
                            <button
                                type="button"
                                class="inline-flex items-center gap-1"
                                :data-release-sort="column.key"
                                @click="sortBy(column.key)"
                            >
                                {{ column.label }}
                                <template v-if="sortKey === column.key">
                                    <ArrowDown
                                        v-if="sortDescending"
                                        class="size-3"
                                    />
                                    <ArrowUp v-else class="size-3" />
                                </template>
                            </button>
                        </th>
                        <th class="px-2 py-1.5"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in sorted"
                        :key="row.key"
                        :class="
                            cn(
                                'border-t border-border align-top',
                                row.rejected && 'opacity-60',
                            )
                        "
                        :data-release-row="row.key"
                    >
                        <td class="max-w-[360px] px-2 py-2">
                            <div class="font-medium break-words">
                                {{ row.title }}
                            </div>
                            <ul
                                v-if="row.rejections.length > 0"
                                class="mt-1 space-y-0.5 text-[11.5px] text-warning"
                            >
                                <li
                                    v-for="reason in row.rejections"
                                    :key="reason"
                                    class="flex items-start gap-1"
                                >
                                    <AlertTriangle
                                        class="mt-0.5 size-3 shrink-0"
                                    />
                                    {{ reason }}
                                </li>
                            </ul>
                        </td>
                        <td class="px-2 py-2">{{ row.quality ?? '—' }}</td>
                        <td class="font-mono-tabular px-2 py-2">
                            {{ formatBytes(row.size) }}
                        </td>
                        <td class="font-mono-tabular px-2 py-2">
                            {{ formatAge(row.age_hours) }}
                        </td>
                        <td class="font-mono-tabular px-2 py-2">
                            {{ row.peers ?? '—' }}
                        </td>
                        <td class="px-2 py-2">{{ row.indexer ?? '—' }}</td>
                        <td class="px-2 py-2 text-right">
                            <Button
                                size="sm"
                                class="h-7 text-xs"
                                :variant="row.rejected ? 'outline' : 'default'"
                                :disabled="grabbing !== null"
                                data-release-grab
                                @click="grab(row)"
                            >
                                <Loader2
                                    v-if="grabbing === row.key"
                                    class="size-3.5 animate-spin"
                                />
                                {{ row.rejected ? 'Grab anyway' : 'Grab' }}
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </DialogContent>
    </Dialog>
</template>
