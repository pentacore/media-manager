<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ScrollText } from 'lucide-vue-next';
import ActivityLogController from '@/actions/App/Http/Controllers/ActivityLogController';
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
import type { ActivityLogResource } from '@/typefinder/resources/ActivityLogResource';

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

interface ServiceOption {
    id: number;
    name: string;
    type: string;
}

const props = defineProps<{
    logs: {
        data: ActivityLogResource[];
        links: PaginatorLink[];
        meta: PaginatorMeta;
    };
    filters: {
        action: string;
        service_id: number | null;
    };
    filterOptions: {
        actions: string[];
        services: ServiceOption[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Activity Log', href: ActivityLogController().url },
        ],
    },
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

function applyFilters(next: { action?: string; service_id?: number | null }) {
    const merged = {
        action: next.action ?? props.filters.action,
        service_id: next.service_id ?? props.filters.service_id,
    };

    const query: Record<string, string | number> = {};

    if (merged.action) {
        query.action = merged.action;
    }

    if (merged.service_id) {
        query.service_id = merged.service_id;
    }

    router.get(ActivityLogController().url, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function onActionChange(value: unknown) {
    const v = typeof value === 'string' ? value : '';
    applyFilters({ action: v === 'all' ? '' : v });
}

function onServiceChange(value: unknown) {
    if (value === 'all' || value === null || value === undefined) {
        applyFilters({ service_id: null });

        return;
    }

    const id = typeof value === 'string' ? Number.parseInt(value, 10) : null;

    applyFilters({ service_id: Number.isFinite(id) ? id : null });
}

function currentActionFilter(): string {
    return props.filters.action === '' ? 'all' : props.filters.action;
}

function currentServiceFilter(): string {
    return props.filters.service_id ? String(props.filters.service_id) : 'all';
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

function hasMetadata(metadata: unknown): boolean {
    return (
        metadata !== null &&
        typeof metadata === 'object' &&
        Object.keys(metadata as Record<string, unknown>).length > 0
    );
}
</script>

<template>
    <Head title="Activity Log" />

    <div class="space-y-6 p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2
                    class="flex items-center gap-2 text-2xl font-bold tracking-tight"
                >
                    <ScrollText class="size-6" />
                    Activity Log
                    <Badge variant="outline">{{ logs.meta.total }}</Badge>
                </h2>
                <p class="text-muted-foreground">
                    Audit trail of webhook-driven changes and approvals across
                    your services.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Select
                    :default-value="currentActionFilter()"
                    @update:model-value="onActionChange"
                >
                    <SelectTrigger class="w-44">
                        <SelectValue placeholder="Action" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All actions</SelectItem>
                        <SelectItem
                            v-for="action in filterOptions.actions"
                            :key="action"
                            :value="action"
                        >
                            {{ action }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <Select
                    :default-value="currentServiceFilter()"
                    @update:model-value="onServiceChange"
                >
                    <SelectTrigger class="w-52">
                        <SelectValue placeholder="Service" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All services</SelectItem>
                        <SelectItem
                            v-for="service in filterOptions.services"
                            :key="service.id"
                            :value="String(service.id)"
                        >
                            {{ service.name }} ({{ service.type }})
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
        </div>

        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead class="w-32">When</TableHead>
                    <TableHead class="w-40">Action</TableHead>
                    <TableHead>Description</TableHead>
                    <TableHead class="w-40">Service</TableHead>
                    <TableHead class="w-40">User</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <template v-for="log in logs.data" :key="log.id">
                    <TableRow>
                        <TableCell class="text-muted-foreground">{{
                            formatTime(log.created_at)
                        }}</TableCell>
                        <TableCell>
                            <Badge variant="secondary">{{ log.action }}</Badge>
                        </TableCell>
                        <TableCell>
                            <div class="flex flex-col gap-1">
                                <span>{{ log.description }}</span>
                                <details
                                    v-if="hasMetadata(log.metadata)"
                                    class="text-xs"
                                >
                                    <summary
                                        class="cursor-pointer text-muted-foreground"
                                    >
                                        metadata
                                    </summary>
                                    <pre
                                        class="mt-1 overflow-auto rounded bg-muted/40 p-2"
                                        >{{ stringifyJson(log.metadata) }}</pre>
                                </details>
                            </div>
                        </TableCell>
                        <TableCell class="text-muted-foreground">
                            <span v-if="log.service_name"
                                >{{ log.service_name }}
                                <span class="text-xs"
                                    >({{ log.service_type }})</span
                                ></span
                            >
                            <span v-else>-</span>
                        </TableCell>
                        <TableCell class="text-muted-foreground">{{
                            log.user_name ?? 'system'
                        }}</TableCell>
                    </TableRow>
                </template>
                <TableRow v-if="logs.data.length === 0">
                    <TableCell
                        :colspan="5"
                        class="py-8 text-center text-muted-foreground"
                    >
                        No activity recorded yet.
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>

        <div
            v-if="logs.meta.last_page > 1"
            class="flex flex-wrap items-center justify-between gap-2"
        >
            <p class="text-sm text-muted-foreground">
                Page {{ logs.meta.current_page }} of {{ logs.meta.last_page }} —
                {{ logs.meta.total }} entries
            </p>
            <div class="flex flex-wrap gap-1">
                <Button
                    v-for="link in logs.links"
                    :key="link.label"
                    :variant="link.active ? 'default' : 'outline'"
                    size="sm"
                    :disabled="!link.url"
                    @click="goToPage(link.url)"
                >
                    <span v-html="link.label" />
                </Button>
            </div>
        </div>
    </div>
</template>
