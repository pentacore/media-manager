<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    Database,
    ExternalLink,
    Play,
    RefreshCcw,
    RefreshCw,
    Trash2,
    Tv,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import RequestController from '@/actions/App/Http/Controllers/Media/RequestController';
import {
    InitialsAvatar,
    OpenInServiceButton,
    Poster,
    StatusPill,
    SvcChip,
} from '@/components/mm';
import { Button } from '@/components/ui/button';
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

type FilterId = 'pending' | 'approved' | 'available' | 'declined' | 'all';

const TABS: { id: FilterId; label: string }[] = [
    { id: 'pending', label: 'Pending review' },
    { id: 'approved', label: 'Approved' },
    { id: 'available', label: 'Now available' },
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

function formatTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const ms = Date.now() - new Date(iso).getTime();
    const m = Math.floor(ms / 60_000);

    if (m < 1) {
        return 'just now';
    }

    if (m < 60) {
        return `${m}m ago`;
    }

    const h = Math.floor(m / 60);

    if (h < 24) {
        return `${h}h ago`;
    }

    return `${Math.floor(h / 24)}d ago`;
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
                    {{ summary.available ?? 0 }} available ·
                    {{ summary.declined }} declined
                </p>
                <Skeleton v-else class="mt-1 h-5 w-64" />
            </div>
            <div class="flex items-center gap-2">
                <Select
                    v-if="userOptions.length > 0"
                    v-model="userFilter"
                >
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
                        <span class="text-[12px] text-fg-subtle">{{
                            formatTime(req.created_at)
                        }}</span>
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
                            v-else-if="req.status === 5"
                            :href="seerrUrl(req) ?? '#'"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="flex h-7 flex-1 items-center justify-center gap-1.5 rounded-md border border-border bg-card px-2 text-xs font-medium hover:bg-bg-hover"
                        >
                            <Play class="size-3.5" />Open in Emby
                        </a>
                        <a
                            v-else
                            :href="seerrUrl(req) ?? '#'"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="flex h-7 flex-1 items-center justify-center gap-1.5 rounded-md text-xs font-medium text-muted-foreground hover:bg-bg-hover hover:text-foreground"
                        >
                            View detail
                        </a>
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
    </div>
</template>
