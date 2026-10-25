<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import ServerController from '@/actions/App/Http/Controllers/Admin/ServerController';
import ServerIncidentController from '@/actions/App/Http/Controllers/Admin/ServerIncidentController';
import { PageHeader, Pill, TimeStamp } from '@/components/mm';
import type { ServerIncidentRow } from '@/components/servers';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useRealtimeReload } from '@/composables/useRealtimeReload';
import { formatCount } from '@/lib/format';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';

interface PaginatorLink {
    url: string | null;
    label: string;
    active: boolean;
}

type IncidentIndexRow = Pick<
    ServerIncidentRow,
    | 'id'
    | 'key'
    | 'severity'
    | 'status'
    | 'title'
    | 'detail'
    | 'opened_at'
    | 'last_seen_at'
    | 'resolved_at'
> & {
    server_id: number;
    server_name: string;
    severity_label: string;
};

const ALL_SERVERS = 'all';

defineProps<{
    incidents: {
        data: IncidentIndexRow[];
        links: PaginatorLink[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    };
    servers: Array<{ id: number; name: string }>;
    filters: { server: number | null };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'Servers', href: ServerController.index.url() },
            {
                title: 'Incidents',
                href: ServerIncidentController.url(),
            },
        ],
    },
});

function setServer(value: unknown): void {
    const server = value === ALL_SERVERS ? undefined : String(value);

    router.get(
        ServerIncidentController.url(),
        { server },
        { preserveScroll: true, preserveState: true },
    );
}

function goToPage(url: string | null): void {
    if (!url) {
        return;
    }

    router.get(url, {}, { preserveScroll: true });
}

const live = useRealtimeReload({
    channel: 'admin.servers',
    event: 'ServerIncidentChanged',
    only: ['incidents'],
});

onMounted(live.subscribe);
</script>

<template>
    <Head title="Incidents" />

    <div class="space-y-6 p-6">
        <PageHeader align="start" class="sm:gap-4">
            <template #title>
                <h1 class="text-[20px] font-semibold">Incidents</h1>
            </template>
            <template #description>
                <p
                    class="mt-1 max-w-[620px] text-[12.5px] text-muted-foreground"
                >
                    Problems the agents found on your servers. Open incidents
                    come first, then the resolved ones.
                </p>
            </template>
            <template #filters>
                <Select
                    :model-value="
                        filters.server === null
                            ? ALL_SERVERS
                            : String(filters.server)
                    "
                    @update:model-value="setServer"
                >
                    <SelectTrigger
                        class="h-8 w-[200px] max-w-full text-sm"
                        aria-label="Filter by server"
                        data-incident-filter
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL_SERVERS"
                            >All servers</SelectItem
                        >
                        <SelectItem
                            v-for="server in servers"
                            :key="server.id"
                            :value="String(server.id)"
                            :aria-label="server.name"
                        >
                            {{ server.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </template>
        </PageHeader>

        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th
                                v-for="h in [
                                    'Server',
                                    'Severity',
                                    'Incident',
                                    'Status',
                                    'Opened',
                                ]"
                                :key="h"
                                class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] whitespace-nowrap text-muted-foreground uppercase"
                            >
                                {{ h }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="incidents.data.length === 0">
                            <td
                                colspan="5"
                                class="px-3 py-10 text-center text-muted-foreground"
                                data-incident-empty
                            >
                                No incidents. Every server is healthy.
                            </td>
                        </tr>
                        <tr
                            v-for="row in incidents.data"
                            :key="row.id"
                            :class="
                                cn(
                                    'border-b border-border last:border-b-0 hover:bg-bg-hover',
                                    row.status === 'resolved' &&
                                        'text-muted-foreground',
                                )
                            "
                            :data-incident-index-row="row.id"
                        >
                            <td class="px-3 py-2.5 whitespace-nowrap">
                                <Link
                                    :href="`${ServerController.show.url(row.server_id)}#incident-${row.id}`"
                                    class="font-medium hover:underline"
                                    data-incident-server
                                >
                                    {{ row.server_name }}
                                </Link>
                            </td>
                            <td class="px-3 py-2.5 whitespace-nowrap">
                                <Pill
                                    :variant="
                                        row.status === 'resolved'
                                            ? 'default'
                                            : row.severity === 'critical'
                                              ? 'danger'
                                              : 'warn'
                                    "
                                    dot
                                    :data-incident-severity="row.severity"
                                >
                                    {{ row.severity_label }}
                                </Pill>
                            </td>
                            <td class="min-w-[220px] px-3 py-2.5">
                                <div
                                    class="font-medium break-words"
                                    data-incident-title
                                >
                                    {{ row.title }}
                                </div>
                                <div
                                    v-if="row.detail"
                                    class="mt-0.5 text-xs break-words text-muted-foreground"
                                >
                                    {{ row.detail }}
                                </div>
                            </td>
                            <td class="px-3 py-2.5 whitespace-nowrap">
                                <Pill
                                    :variant="
                                        row.status === 'open'
                                            ? 'warn'
                                            : 'default'
                                    "
                                    data-incident-status
                                >
                                    {{
                                        row.status === 'open'
                                            ? 'Open'
                                            : 'Resolved'
                                    }}
                                </Pill>
                            </td>
                            <td class="px-3 py-2.5 whitespace-nowrap">
                                <TimeStamp
                                    :iso="row.opened_at"
                                    mode="datetime"
                                    class="text-[12px] text-muted-foreground"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div
            v-if="incidents.meta.last_page > 1"
            class="flex flex-wrap items-center justify-between gap-2"
            data-incident-pagination
        >
            <p class="text-sm text-muted-foreground">
                Page {{ incidents.meta.current_page }} of
                {{ incidents.meta.last_page }} —
                <span class="font-mono-tabular">{{
                    formatCount(incidents.meta.total)
                }}</span>
                incidents
            </p>
            <div class="flex flex-wrap gap-1">
                <Button
                    v-for="link in incidents.links"
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
