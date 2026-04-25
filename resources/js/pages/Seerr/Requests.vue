<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    Database,
    ExternalLink,
    RefreshCw,
    Trash2,
    Tv,
    X,
} from 'lucide-vue-next';
import { computed } from 'vue';
import RequestController from '@/actions/App/Http/Controllers/Media/RequestController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
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
}

const props = defineProps<{
    connection: { url: string };
    filters: { page: number };
    requests?: { data: SeerrRequest[]; meta: Meta };
    summary?: Summary;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Requests', href: RequestController.index.url() },
        ],
    },
});

const page = usePage();

const isAdmin = computed(() => {
    const role = page.props.auth.user?.role;

    if (!role) {
        return false;
    }

    const value = typeof role === 'string' ? role : role.value;

    return value === 'admin';
});

function statusLabel(status: number | null): string {
    switch (status) {
        case 1:
            return 'Pending';
        case 2:
            return 'Approved';
        case 3:
            return 'Declined';
        default:
            return 'Unknown';
    }
}

function statusVariant(
    status: number | null,
): 'secondary' | 'default' | 'destructive' | 'outline' {
    switch (status) {
        case 1:
            return 'secondary';
        case 2:
            return 'default';
        case 3:
            return 'destructive';
        default:
            return 'outline';
    }
}

function mediaTypeLabel(type: string | null): string {
    if (type === 'movie') {
        return 'Movie';
    }

    if (type === 'tv') {
        return 'TV';
    }

    return type ?? 'Unknown';
}

function formatTime(iso: string | null): string {
    if (!iso) {
        return '-';
    }

    const date = new Date(iso);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);

    if (diffMins < 1) {
        return 'Just now';
    }

    if (diffMins < 60) {
        return `${diffMins}m ago`;
    }

    const diffHours = Math.floor(diffMins / 60);

    if (diffHours < 24) {
        return `${diffHours}h ago`;
    }

    const diffDays = Math.floor(diffHours / 24);

    if (diffDays < 30) {
        return `${diffDays}d ago`;
    }

    return date.toISOString().slice(0, 10);
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
    router.get(RequestController.index.url({ query: { page: targetPage } }));
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

    return `Showing ${start}-${end} of ${m.total}`;
});
</script>

<template>
    <Head title="Requests" />

    <div class="space-y-6 p-6">
        <div>
            <h2 class="text-2xl font-bold tracking-tight">Media Requests</h2>
            <p v-if="summary" class="text-muted-foreground">
                {{ summary.total }} total · {{ summary.pending }} pending ·
                {{ summary.approved }} approved
            </p>
            <Skeleton v-else class="mt-1 h-5 w-64" />
        </div>

        <TooltipProvider :delay-duration="200">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Status</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Title</TableHead>
                        <TableHead>Requester</TableHead>
                        <TableHead>Created</TableHead>
                        <TableHead class="text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-if="requests">
                        <TableRow v-for="req in requests.data" :key="req.id">
                            <TableCell>
                                <Badge :variant="statusVariant(req.status)">{{
                                    statusLabel(req.status)
                                }}</Badge>
                            </TableCell>
                            <TableCell>
                                <Badge variant="outline">{{
                                    mediaTypeLabel(req.media_type)
                                }}</Badge>
                            </TableCell>
                            <TableCell class="font-medium">{{
                                req.media_title ?? 'Unknown'
                            }}</TableCell>
                            <TableCell class="text-muted-foreground">{{
                                req.requester ?? '-'
                            }}</TableCell>
                            <TableCell class="text-muted-foreground">{{
                                formatTime(req.created_at)
                            }}</TableCell>
                            <TableCell class="text-right">
                                <div class="inline-flex items-center gap-1">
                                    <Tooltip v-if="seerrUrl(req)">
                                        <TooltipTrigger as-child>
                                            <a
                                                :href="seerrUrl(req)!"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                >
                                                    <ExternalLink
                                                        class="size-4"
                                                    />
                                                    <span class="sr-only"
                                                        >Open in Seerr</span
                                                    >
                                                </Button>
                                            </a>
                                        </TooltipTrigger>
                                        <TooltipContent
                                            >Open in Seerr</TooltipContent
                                        >
                                    </Tooltip>
                                    <Tooltip v-if="tmdbUrl(req)">
                                        <TooltipTrigger as-child>
                                            <a
                                                :href="tmdbUrl(req)!"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                >
                                                    <Database class="size-4" />
                                                    <span class="sr-only"
                                                        >Open on TMDB</span
                                                    >
                                                </Button>
                                            </a>
                                        </TooltipTrigger>
                                        <TooltipContent
                                            >Open on TMDB</TooltipContent
                                        >
                                    </Tooltip>
                                    <Tooltip v-if="tvdbUrl(req)">
                                        <TooltipTrigger as-child>
                                            <a
                                                :href="tvdbUrl(req)!"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                >
                                                    <Tv class="size-4" />
                                                    <span class="sr-only"
                                                        >Open on TVDB</span
                                                    >
                                                </Button>
                                            </a>
                                        </TooltipTrigger>
                                        <TooltipContent
                                            >Open on TVDB</TooltipContent
                                        >
                                    </Tooltip>
                                    <template v-if="req.status === 1">
                                        <Tooltip>
                                            <TooltipTrigger as-child>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    @click="approveRequest(req)"
                                                >
                                                    <Check class="size-4" />
                                                    <span class="sr-only"
                                                        >Approve</span
                                                    >
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent
                                                >Approve</TooltipContent
                                            >
                                        </Tooltip>
                                        <Tooltip>
                                            <TooltipTrigger as-child>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    @click="declineRequest(req)"
                                                >
                                                    <X class="size-4" />
                                                    <span class="sr-only"
                                                        >Decline</span
                                                    >
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent
                                                >Decline</TooltipContent
                                            >
                                        </Tooltip>
                                    </template>
                                    <Tooltip v-if="isAdmin">
                                        <TooltipTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                @click="retryRequest(req)"
                                            >
                                                <RefreshCw class="size-4" />
                                                <span class="sr-only"
                                                    >Retry</span
                                                >
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>Retry</TooltipContent>
                                    </Tooltip>
                                    <Tooltip v-if="isAdmin">
                                        <TooltipTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="text-destructive hover:text-destructive"
                                                @click="deleteRequest(req)"
                                            >
                                                <Trash2 class="size-4" />
                                                <span class="sr-only"
                                                    >Delete</span
                                                >
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>Delete</TooltipContent>
                                    </Tooltip>
                                </div>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="requests.data.length === 0">
                            <TableCell
                                :colspan="6"
                                class="py-8 text-center text-muted-foreground"
                            >
                                No requests found.
                            </TableCell>
                        </TableRow>
                    </template>
                    <template v-else>
                        <TableRow v-for="i in 8" :key="`skeleton-${i}`">
                            <TableCell><Skeleton class="h-5 w-16" /></TableCell>
                            <TableCell><Skeleton class="h-5 w-12" /></TableCell>
                            <TableCell><Skeleton class="h-5 w-48" /></TableCell>
                            <TableCell><Skeleton class="h-5 w-24" /></TableCell>
                            <TableCell><Skeleton class="h-5 w-16" /></TableCell>
                            <TableCell class="text-right"
                                ><Skeleton class="inline-block h-8 w-32"
                            /></TableCell>
                        </TableRow>
                    </template>
                </TableBody>
            </Table>
        </TooltipProvider>

        <div v-if="meta" class="flex items-center justify-between">
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
