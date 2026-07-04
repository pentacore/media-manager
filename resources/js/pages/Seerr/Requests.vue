<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    ChevronsDown,
    Database,
    ExternalLink,
    Loader2,
    Pencil,
    RefreshCcw,
    RefreshCw,
    Trash2,
    Tv,
    X,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import RequestController from '@/actions/App/Http/Controllers/Media/RequestController';
import {
    InitialsAvatar,
    OpenInServiceButton,
    Poster,
    StatusPill,
    SvcChip,
    TimeStamp,
} from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { useRealtimeReload } from '@/composables/useRealtimeReload';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';

interface SeerrRequest {
    id: number;
    status: number | null;
    media_type: string | null;
    media_title: string | null;
    tmdb_id: number | null;
    tvdb_id: number | null;
    requester: string | null;
    created_at: string | null;
    updated_at: string | null;
}

interface Meta {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

interface Summary {
    total: number;
    pending: number;
    approved: number;
    declined: number;
    available?: number;
    processing?: number;
    completed?: number;
}

const props = defineProps<{
    connection: { url: string };
    filters: { page: number; status: FilterId };
    requests?: { data: SeerrRequest[]; meta: Meta };
    summary?: Summary;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Media', href: dashboard().url },
            { title: 'Requests', href: RequestController.index.url() },
        ],
    },
});

const { subscribe: subscribeReload } = useRealtimeReload<{
    service_type: string | null;
}>({
    channel: 'dashboard',
    event: 'WebhookReceived',
    only: ['requests', 'summary'],
    filter: (event) => event.service_type === 'seerr',
});

onMounted(subscribeReload);

const page = usePage();

const isAdmin = computed(() => {
    const role = page.props.auth.user?.role;

    if (!role) {
        return false;
    }

    const value = typeof role === 'string' ? role : role.value;

    return value === 'admin';
});

type FilterId =
    | 'pending'
    | 'approved'
    | 'processing'
    | 'available'
    | 'completed'
    | 'declined'
    | 'all';

const TABS: { id: FilterId; label: string }[] = [
    { id: 'pending', label: 'Pending review' },
    { id: 'approved', label: 'Approved' },
    { id: 'processing', label: 'Requested' },
    { id: 'available', label: 'Now available' },
    { id: 'completed', label: 'Completed' },
    { id: 'declined', label: 'Declined' },
    { id: 'all', label: 'All' },
];

const userFilter = ref<string>('all');
const syncing = ref(false);

function setStatus(id: FilterId): void {
    if (id === props.filters.status) {
        return;
    }

    router.get(
        RequestController.index.url(),
        id === 'pending' ? {} : { status: id },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function syncSeerr(): void {
    if (syncing.value) {
        return;
    }

    syncing.value = true;
    router.reload({
        only: ['requests', 'summary'],
        onFinish: () => {
            syncing.value = false;
        },
    });
}

function statusKey(status: number | null): FilterId | 'failed' | 'unknown' {
    switch (status) {
        case 1:
            return 'pending';
        case 2:
            return 'approved';
        case 3:
            return 'declined';
        case 4:
            return 'failed';
        case 5:
            return 'available';
        default:
            return 'unknown';
    }
}

const userOptions = computed<string[]>(() => {
    const set = new Set<string>();

    for (const req of props.requests?.data ?? []) {
        if (req.requester) {
            set.add(req.requester);
        }
    }

    return [...set].sort();
});

const visible = computed<SeerrRequest[]>(() => {
    if (!props.requests) {
        return [];
    }

    if (userFilter.value === 'all') {
        return props.requests.data;
    }

    return props.requests.data.filter(
        (req) => req.requester === userFilter.value,
    );
});

function counts(id: FilterId): number {
    if (!props.summary) {
        return 0;
    }

    if (id === 'all') {
        return props.summary.total;
    }

    if (id === 'pending') {
        return props.summary.pending;
    }

    if (id === 'approved') {
        return props.summary.approved;
    }

    if (id === 'declined') {
        return props.summary.declined;
    }

    if (id === 'available') {
        return props.summary.available ?? 0;
    }

    if (id === 'processing') {
        return props.summary.processing ?? 0;
    }

    if (id === 'completed') {
        return props.summary.completed ?? 0;
    }

    return 0;
}

function mediaTypeLabel(type: string | null): string {
    if (type === 'movie') {
        return 'Movie';
    }

    if (type === 'tv') {
        return 'Series';
    }

    return type ?? 'Unknown';
}

function seerrUrl(req: SeerrRequest): string | null {
    if (req.tmdb_id === null) {
        return null;
    }

    const path = req.media_type === 'movie' ? 'movie' : 'tv';

    return `${props.connection.url}/${path}/${req.tmdb_id}`;
}

function tmdbUrl(req: SeerrRequest): string | null {
    if (req.tmdb_id === null) {
        return null;
    }

    const path = req.media_type === 'movie' ? 'movie' : 'tv';

    return `https://www.themoviedb.org/${path}/${req.tmdb_id}`;
}

function tvdbUrl(req: SeerrRequest): string | null {
    if (req.tvdb_id === null || req.media_type !== 'tv') {
        return null;
    }

    return `https://thetvdb.com/dereferrer/series/${req.tvdb_id}`;
}

function deleteRequest(req: SeerrRequest) {
    if (
        confirm(
            `Delete request for "${req.media_title ?? 'this item'}"? This cannot be undone.`,
        )
    ) {
        router.delete(RequestController.destroy.url(req.id), {
            preserveScroll: true,
        });
    }
}

function approveRequest(req: SeerrRequest) {
    router.visit(RequestController.approve.url(req.id), {
        method: 'post',
        preserveScroll: true,
    });
}

function declineRequest(req: SeerrRequest) {
    if (confirm(`Decline request for "${req.media_title ?? 'this item'}"?`)) {
        router.visit(RequestController.decline.url(req.id), {
            method: 'post',
            preserveScroll: true,
        });
    }
}

function retryRequest(req: SeerrRequest) {
    router.visit(RequestController.retry.url(req.id), {
        method: 'post',
        preserveScroll: true,
    });
}

interface EditOptions {
    media_type: string | null;
    current: {
        profile_id: number | null;
        root_folder: string | null;
        server_id: number | null;
        is4k: boolean;
        media_id: number | null;
    };
    profiles: { id: number | null; name: string | null }[];
    root_folders: { path: string | null; free_space: number | null }[];
}

const editingRequest = ref<SeerrRequest | null>(null);
const editLoading = ref(false);
const editError = ref<string | null>(null);
const editOptions = ref<EditOptions | null>(null);
const editProfileId = ref<string>('');
const editRootFolder = ref<string>('');
const editSubmitting = ref(false);

function openEdit(req: SeerrRequest): void {
    editingRequest.value = req;
    editOptions.value = null;
    editError.value = null;
    editLoading.value = true;
    editProfileId.value = '';
    editRootFolder.value = '';

    fetch(RequestController.editOptions.url(req.id), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    })
        .then(async (response) => {
            const body = (await response.json()) as
                EditOptions | { error: string };

            if (!response.ok) {
                editError.value =
                    'error' in body
                        ? body.error
                        : `Request failed (${response.status})`;

                return;
            }

            const opts = body as EditOptions;
            editOptions.value = opts;
            editProfileId.value =
                opts.current.profile_id !== null
                    ? String(opts.current.profile_id)
                    : '';
            editRootFolder.value = opts.current.root_folder ?? '';
        })
        .catch((error: unknown) => {
            editError.value =
                error instanceof Error ? error.message : 'Network error';
        })
        .finally(() => {
            editLoading.value = false;
        });
}

function closeEdit(): void {
    if (editSubmitting.value) {
        return;
    }

    editingRequest.value = null;
    editOptions.value = null;
    editError.value = null;
}

function submitEdit(): void {
    const req = editingRequest.value;

    if (!req || editProfileId.value === '' || editRootFolder.value === '') {
        return;
    }

    editSubmitting.value = true;
    router.put(
        RequestController.update.url(req.id),
        {
            profile_id: Number(editProfileId.value),
            root_folder: editRootFolder.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                editingRequest.value = null;
                router.reload({ only: ['requests', 'summary'] });
            },
            onFinish: () => {
                editSubmitting.value = false;
            },
        },
    );
}

const CLEARABLE_STATUSES = [
    'completed',
    'available',
    'declined',
    'failed',
] as const;
type ClearableStatus = (typeof CLEARABLE_STATUSES)[number];

const CLEAR_LABELS: Record<ClearableStatus, string> = {
    completed: 'Clear completed',
    available: 'Clear available',
    declined: 'Clear declined',
    failed: 'Clear failed',
};

const CLEAR_DESCRIPTIONS: Record<ClearableStatus, string> = {
    completed: 'requests already imported and watched',
    available: 'requests whose media is already in the library',
    declined: 'requests an admin has rejected',
    failed: 'requests Seerr could not push to Sonarr/Radarr',
};

const clearing = ref(false);

function clearByStatus(status: ClearableStatus): void {
    if (clearing.value) {
        return;
    }

    if (
        !confirm(
            `Permanently delete every ${status} Seerr request (${CLEAR_DESCRIPTIONS[status]})? This cannot be undone.`,
        )
    ) {
        return;
    }

    clearing.value = true;
    router.post(
        RequestController.clear.url(),
        { status },
        {
            preserveScroll: true,
            onFinish: () => {
                clearing.value = false;
            },
        },
    );
}

function goToPage(targetPage: number) {
    const query: Record<string, string | number> = { page: targetPage };

    if (props.filters.status !== 'pending') {
        query.status = props.filters.status;
    }

    router.get(RequestController.index.url({ query }));
}

const meta = computed(() => props.requests?.meta);
const hasPrev = computed(() => (meta.value?.current_page ?? 1) > 1);
const hasNext = computed(
    () => (meta.value?.current_page ?? 1) < (meta.value?.last_page ?? 1),
);
const rangeText = computed(() => {
    const m = meta.value;

    if (!m) {
        return '';
    }

    const start = (m.current_page - 1) * m.per_page + 1;
    const end = Math.min(m.current_page * m.per_page, m.total);

    return `Showing ${start}–${end} of ${m.total}`;
});
</script>

<template>
    <Head title="Requests" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero -->
        <div class="flex items-end justify-between gap-3">
            <div>
                <div class="mb-1.5 flex items-center gap-2">
                    <SvcChip id="seerr" />
                    <span class="text-fg-subtle">/</span>
                    <span class="text-[13px] text-muted-foreground"
                        >Requests</span
                    >
                </div>
                <h1
                    class="text-[22px] leading-tight font-semibold tracking-tight"
                >
                    Household requests
                </h1>
                <p
                    v-if="summary"
                    class="mt-1 text-[13px] text-muted-foreground"
                >
                    {{ summary.pending }} pending ·
                    {{ summary.approved }} approved ·
                    {{ summary.processing ?? 0 }} requested ·
                    {{ summary.available ?? 0 }} available ·
                    {{ summary.completed ?? 0 }} completed ·
                    {{ summary.declined }} declined
                </p>
                <Skeleton v-else class="mt-1 h-5 w-64" />
            </div>
            <div class="flex items-center gap-2">
                <Select v-if="userOptions.length > 0" v-model="userFilter">
                    <SelectTrigger class="h-7 w-32 text-xs">
                        <SelectValue placeholder="User" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All users</SelectItem>
                        <SelectItem
                            v-for="user in userOptions"
                            :key="user"
                            :value="user"
                        >
                            {{ user }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Button
                    variant="outline"
                    size="sm"
                    class="h-7 gap-1.5 text-xs"
                    :disabled="syncing"
                    @click="syncSeerr"
                >
                    <RefreshCcw
                        class="size-3.5"
                        :class="{ 'animate-spin': syncing }"
                    />Sync Seerr
                </Button>
                <DropdownMenu v-if="isAdmin">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-7 gap-1.5 text-xs"
                            :disabled="clearing"
                        >
                            <Trash2 class="size-3.5" />Clear
                            <ChevronsDown class="size-3" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <DropdownMenuLabel>Bulk delete</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            v-for="status in CLEARABLE_STATUSES"
                            :key="status"
                            class="text-destructive focus:text-destructive"
                            @select="clearByStatus(status)"
                        >
                            {{ CLEAR_LABELS[status] }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <OpenInServiceButton
                    :href="props.connection.url"
                    label="Open Seerr"
                />
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex flex-wrap items-center gap-1.5">
            <button
                v-for="tab in TABS"
                :key="tab.id"
                type="button"
                :class="
                    cn(
                        'inline-flex h-7 items-center gap-1.5 rounded-md px-2.5 text-xs font-medium transition-colors',
                        filters.status === tab.id
                            ? 'bg-accent text-accent-foreground'
                            : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                    )
                "
                @click="setStatus(tab.id)"
            >
                {{ tab.label }}
                <span class="font-mono-tabular text-[11px] opacity-70">{{
                    counts(tab.id)
                }}</span>
            </button>
        </div>

        <!-- Cards -->
        <div
            v-if="requests"
            class="grid gap-4"
            style="grid-template-columns: repeat(auto-fill, minmax(320px, 1fr))"
        >
            <div
                v-for="req in visible"
                :key="req.id"
                class="flex gap-3.5 rounded-xl border border-border bg-card p-3.5"
            >
                <Poster
                    :hint="
                        (req.media_title ?? 'media').toLowerCase().slice(0, 12)
                    "
                    size="lg"
                />
                <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                    <div class="flex items-center justify-between">
                        <span
                            class="font-mono-tabular text-[11px] text-fg-subtle"
                            >req_{{ req.id }}</span
                        >
                        <StatusPill :status="statusKey(req.status)" />
                    </div>
                    <div
                        class="text-[15px] leading-tight font-semibold text-pretty"
                    >
                        {{ req.media_title ?? 'Unknown' }}
                    </div>
                    <div class="text-[12px] text-muted-foreground">
                        {{ mediaTypeLabel(req.media_type) }}
                    </div>
                    <div v-if="req.requester" class="flex items-center gap-2">
                        <InitialsAvatar :name="req.requester" :size="20" />
                        <span class="text-[12px]">{{ req.requester }}</span>
                        <span class="text-[12px] text-fg-subtle">·</span>
                        <TimeStamp
                            :iso="req.created_at"
                            class="text-[12px] text-fg-subtle"
                        />
                    </div>

                    <div class="mt-auto flex items-center gap-2 pt-1.5">
                        <template v-if="req.status === 1">
                            <Button
                                size="sm"
                                class="h-7 flex-1 text-xs"
                                @click="approveRequest(req)"
                            >
                                <Check class="size-3.5" />Approve
                            </Button>
                            <Button
                                size="sm"
                                variant="destructive"
                                class="h-7 text-xs"
                                @click="declineRequest(req)"
                            >
                                <X class="size-3.5" />Decline
                            </Button>
                        </template>
                        <a
                            v-if="tmdbUrl(req)"
                            :href="tmdbUrl(req)!"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-bg-hover"
                            title="TMDB"
                        >
                            <Database class="size-3.5" />
                        </a>
                        <a
                            v-if="tvdbUrl(req)"
                            :href="tvdbUrl(req)!"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-bg-hover"
                            title="TVDB"
                        >
                            <Tv class="size-3.5" />
                        </a>
                        <a
                            v-if="seerrUrl(req)"
                            :href="seerrUrl(req)!"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-bg-hover"
                            title="Seerr"
                        >
                            <ExternalLink class="size-3.5" />
                        </a>
                        <button
                            v-if="isAdmin"
                            type="button"
                            class="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-bg-hover"
                            title="Edit profile / root folder"
                            @click="openEdit(req)"
                        >
                            <Pencil class="size-3.5" />
                        </button>
                        <button
                            v-if="isAdmin"
                            type="button"
                            class="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-bg-hover"
                            title="Retry"
                            @click="retryRequest(req)"
                        >
                            <RefreshCw class="size-3.5" />
                        </button>
                        <button
                            v-if="isAdmin"
                            type="button"
                            class="inline-flex size-7 items-center justify-center rounded-md text-destructive hover:bg-destructive/10"
                            title="Delete"
                            @click="deleteRequest(req)"
                        >
                            <Trash2 class="size-3.5" />
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-if="visible.length === 0"
                class="col-span-full rounded-xl border border-border bg-card p-9 text-center text-sm text-fg-subtle"
            >
                No requests in this view.
            </div>
        </div>

        <div
            v-else
            class="grid gap-4"
            style="grid-template-columns: repeat(auto-fill, minmax(320px, 1fr))"
        >
            <Skeleton
                v-for="n in 6"
                :key="`req-skel-${n}`"
                class="h-[160px] w-full rounded-xl"
            />
        </div>

        <!-- Pagination -->
        <div
            v-if="meta && meta.last_page > 1"
            class="flex items-center justify-between"
        >
            <p class="text-sm text-muted-foreground">{{ rangeText }}</p>
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!hasPrev"
                    @click="goToPage(meta.current_page - 1)"
                >
                    <ChevronLeft class="size-4" /> Prev
                </Button>
                <span class="text-sm text-muted-foreground">
                    Page {{ meta.current_page }} of {{ meta.last_page }}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!hasNext"
                    @click="goToPage(meta.current_page + 1)"
                >
                    Next <ChevronRight class="size-4" />
                </Button>
            </div>
        </div>

        <!-- Edit request dialog -->
        <Dialog
            :open="editingRequest !== null"
            @update:open="(v) => !v && closeEdit()"
        >
            <DialogContent v-if="editingRequest" class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Edit request —
                        {{ editingRequest.media_title ?? 'item' }}
                    </DialogTitle>
                </DialogHeader>
                <div
                    v-if="editLoading"
                    class="flex items-center gap-2 py-6 text-sm text-muted-foreground"
                >
                    <Loader2 class="size-4 animate-spin" />
                    Loading options…
                </div>
                <div
                    v-else-if="editError"
                    class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-[12px] text-destructive"
                >
                    {{ editError }}
                </div>
                <div v-else-if="editOptions" class="space-y-4">
                    <div class="space-y-2">
                        <Label>Quality profile</Label>
                        <Select v-model="editProfileId">
                            <SelectTrigger>
                                <SelectValue placeholder="Pick a profile" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="profile in editOptions.profiles"
                                    :key="profile.id ?? 0"
                                    :value="String(profile.id ?? 0)"
                                >
                                    {{ profile.name ?? 'Unnamed' }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="space-y-2">
                        <Label>Root folder</Label>
                        <Select v-model="editRootFolder">
                            <SelectTrigger>
                                <SelectValue placeholder="Pick a folder" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="folder in editOptions.root_folders"
                                    :key="folder.path ?? ''"
                                    :value="folder.path ?? ''"
                                >
                                    {{ folder.path ?? '—' }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        :disabled="editSubmitting"
                        @click="closeEdit"
                    >
                        Cancel
                    </Button>
                    <Button
                        :disabled="
                            editSubmitting ||
                            editLoading ||
                            editProfileId === '' ||
                            editRootFolder === ''
                        "
                        @click="submitEdit"
                    >
                        <Loader2
                            v-if="editSubmitting"
                            class="mr-1.5 size-3.5 animate-spin"
                        />Save changes
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
