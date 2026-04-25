<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Zap } from 'lucide-vue-next';
import { computed } from 'vue';
import ActionRequestController from '@/actions/App/Http/Controllers/Actions/ActionRequestController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes';
import type { ActionRequestResource } from '@/typefinder/resources/ActionRequestResource';

type ActionRequestRow = ActionRequestResource;

interface PaginatorLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatorMeta {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

const props = defineProps<{
    requests: {
        data: ActionRequestRow[];
        links: PaginatorLink[];
        meta: PaginatorMeta;
    };
    filters: { status: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            {
                title: 'Action Requests',
                href: ActionRequestController.index.url(),
            },
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

    return `${diffDays}d ago`;
}

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'outline' | 'destructive' {
    switch (status) {
        case 'pending':
            return 'secondary';
        case 'approved':
        case 'executing':
            return 'default';
        case 'completed':
            return 'outline';
        case 'failed':
        case 'rejected':
            return 'destructive';
        default:
            return 'outline';
    }
}

function onStatusChange(value: unknown) {
    const v = typeof value === 'string' ? value : '';
    router.get(
        ActionRequestController.index.url(),
        v === 'all' ? {} : { status: v },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function currentFilter(): string {
    return props.filters.status === '' ? 'all' : props.filters.status;
}

function approve(id: number) {
    router.post(
        ActionRequestController.approve.url(id),
        {},
        { preserveScroll: true },
    );
}

function reject(id: number) {
    router.post(
        ActionRequestController.reject.url(id),
        {},
        { preserveScroll: true },
    );
}

function retry(id: number) {
    router.post(
        ActionRequestController.retry.url(id),
        {},
        { preserveScroll: true },
    );
}

function goToPage(url: string | null) {
    if (!url) {
        return;
    }

    router.get(url, {}, { preserveState: true, preserveScroll: true });
}

function stringifyJson(value: unknown): string {
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}
</script>

<template>
    <Head title="Action Requests" />

    <div class="space-y-6 p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2
                    class="flex items-center gap-2 text-2xl font-bold tracking-tight"
                >
                    <Zap class="size-6" />
                    Action Requests
                    <Badge variant="outline">{{ requests.meta.total }}</Badge>
                </h2>
                <p class="text-muted-foreground">
                    Review pending automations and re-run failed actions.
                </p>
            </div>

            <Select
                :default-value="currentFilter()"
                @update:model-value="onStatusChange"
            >
                <SelectTrigger class="w-44">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All statuses</SelectItem>
                    <SelectItem value="pending">Pending</SelectItem>
                    <SelectItem value="approved">Approved</SelectItem>
                    <SelectItem value="executing">Executing</SelectItem>
                    <SelectItem value="completed">Completed</SelectItem>
                    <SelectItem value="failed">Failed</SelectItem>
                    <SelectItem value="rejected">Rejected</SelectItem>
                </SelectContent>
            </Select>
        </div>

        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Created</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Flow</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Approval</TableHead>
                    <TableHead>Source</TableHead>
                    <TableHead>Approved By</TableHead>
                    <TableHead class="text-right">Actions</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="request in requests.data" :key="request.id">
                    <TableCell class="text-muted-foreground">{{
                        formatTime(request.created_at)
                    }}</TableCell>
                    <TableCell class="font-medium">{{
                        request.type
                    }}</TableCell>
                    <TableCell class="text-muted-foreground">
                        {{ request.source_service }} &rarr;
                        {{ request.target_service }}
                    </TableCell>
                    <TableCell>
                        <Badge :variant="statusVariant(request.status)">{{
                            request.status
                        }}</Badge>
                    </TableCell>
                    <TableCell>
                        <span
                            v-if="request.requires_approval"
                            class="text-foreground"
                            >&#10003;</span
                        >
                        <span v-else class="text-muted-foreground"
                            >&#10007;</span
                        >
                    </TableCell>
                    <TableCell class="text-muted-foreground">{{
                        request.webhook_source ?? '-'
                    }}</TableCell>
                    <TableCell class="text-muted-foreground">{{
                        request.approved_by ?? '-'
                    }}</TableCell>
                    <TableCell class="space-x-2 text-right">
                        <template v-if="request.status === 'pending'">
                            <Button size="sm" @click="approve(request.id)"
                                >Approve</Button
                            >
                            <Button
                                variant="destructive"
                                size="sm"
                                @click="reject(request.id)"
                                >Reject</Button
                            >
                        </template>
                        <Button
                            v-else-if="request.status === 'failed' && isAdmin"
                            variant="outline"
                            size="sm"
                            @click="retry(request.id)"
                        >
                            Retry
                        </Button>
                        <span v-else class="text-xs text-muted-foreground"
                            >-</span
                        >
                    </TableCell>
                </TableRow>
                <TableRow v-if="requests.data.length === 0">
                    <TableCell
                        :colspan="8"
                        class="py-8 text-center text-muted-foreground"
                    >
                        No action requests.
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>

        <div
            v-if="requests.data.some((r) => r.status === 'pending' || r.result)"
            class="space-y-2"
        >
            <details
                v-for="request in requests.data.filter(
                    (r) => r.status === 'pending' || r.result,
                )"
                :key="`details-${request.id}`"
                class="rounded-md border bg-muted/30 p-3 text-xs"
            >
                <summary class="cursor-pointer font-medium">
                    #{{ request.id }} {{ request.type }} — payload &amp; result
                </summary>
                <div class="mt-2 grid gap-3 md:grid-cols-2">
                    <div>
                        <div class="mb-1 text-muted-foreground">Payload</div>
                        <pre class="overflow-auto rounded bg-background p-2">{{
                            stringifyJson(request.payload)
                        }}</pre>
                    </div>
                    <div>
                        <div class="mb-1 text-muted-foreground">Result</div>
                        <pre class="overflow-auto rounded bg-background p-2">{{
                            request.result ? stringifyJson(request.result) : '—'
                        }}</pre>
                    </div>
                </div>
            </details>
        </div>

        <div
            v-if="requests.links.length > 3"
            class="flex flex-wrap items-center gap-2"
        >
            <Button
                v-for="(link, index) in requests.links"
                :key="index"
                variant="outline"
                size="sm"
                :disabled="!link.url"
                :class="link.active ? 'bg-accent' : ''"
                @click="goToPage(link.url)"
            >
                <span v-html="link.label" />
            </Button>
        </div>
    </div>
</template>
