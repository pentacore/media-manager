<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import RequestController from '@/actions/App/Http/Controllers/Media/RequestController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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

const props = defineProps<{ requests: SeerrRequest[] }>();

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

const pendingCount = computed(
    () => props.requests.filter((r) => r.status === 1).length,
);

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

function deleteRequest(req: SeerrRequest) {
    if (
        confirm(
            `Delete request for "${req.media_title ?? 'Unknown'}"? This cannot be undone.`,
        )
    ) {
        router.delete(RequestController.destroy.url(req.id), {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Head title="Requests" />

    <div class="space-y-6 p-6">
        <div>
            <h2 class="text-2xl font-bold tracking-tight">Media Requests</h2>
            <p class="text-muted-foreground">
                {{ requests.length }} total, {{ pendingCount }} pending
            </p>
        </div>

        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Status</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Title</TableHead>
                    <TableHead>Requester</TableHead>
                    <TableHead>Created</TableHead>
                    <TableHead v-if="isAdmin" class="text-right"
                        >Actions</TableHead
                    >
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="req in requests" :key="req.id">
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
                    <TableCell v-if="isAdmin" class="text-right">
                        <Button
                            variant="ghost"
                            size="sm"
                            class="text-destructive"
                            @click="deleteRequest(req)"
                        >
                            <Trash2 class="size-4" />
                            <span class="sr-only">Delete</span>
                        </Button>
                    </TableCell>
                </TableRow>
                <TableRow v-if="requests.length === 0">
                    <TableCell
                        :colspan="isAdmin ? 6 : 5"
                        class="py-8 text-center text-muted-foreground"
                    >
                        No requests found.
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </div>
</template>
