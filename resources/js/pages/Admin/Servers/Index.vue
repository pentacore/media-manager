<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import ServerController from '@/actions/App/Http/Controllers/Admin/ServerController';
import { PageHeader, Pill, TimeStamp } from '@/components/mm';
import {
    AddServerDialog,
    ServerStatusDot,
    WatcherSettings,
} from '@/components/servers';
import type { ServerRow, WatcherState } from '@/components/servers';
import { Button } from '@/components/ui/button';
import { useRealtimeReload } from '@/composables/useRealtimeReload';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';

interface PaginatorLink {
    url: string | null;
    label: string;
    active: boolean;
}

defineProps<{
    servers: {
        data: ServerRow[];
        links: PaginatorLink[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    };
    latestAgentVersion: string | null;
    watcher: WatcherState;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'Servers', href: ServerController.index.url() },
        ],
    },
});

function goToPage(url: string | null): void {
    if (!url) {
        return;
    }

    router.get(url, {}, { preserveScroll: true });
}

const reports = useRealtimeReload({
    channel: 'admin.servers',
    event: 'ServerReported',
    only: ['servers'],
});

const incidents = useRealtimeReload({
    channel: 'admin.servers',
    event: 'ServerIncidentChanged',
    only: ['servers'],
});

onMounted(() => {
    reports.subscribe();
    incidents.subscribe();
});
</script>

<template>
    <Head title="Servers" />

    <div class="space-y-6 p-6">
        <PageHeader align="start" class="sm:gap-4">
            <template #title>
                <h1 class="text-[20px] font-semibold">Servers</h1>
            </template>
            <template #description>
                <p
                    class="mt-1 max-w-[620px] text-[12.5px] text-muted-foreground"
                >
                    Hosts running the Lookout agent. Each one reports its health
                    every minute and opens an incident when something needs
                    attention.
                    <span v-if="latestAgentVersion" data-server-latest-version>
                        Latest agent release:
                        <span class="font-mono-tabular text-foreground">{{
                            latestAgentVersion
                        }}</span
                        >.
                    </span>
                </p>
            </template>
            <template #primaryAction>
                <AddServerDialog />
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
                                    'Status',
                                    'Last report',
                                    'Agent',
                                    'Open incidents',
                                ]"
                                :key="h"
                                class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] whitespace-nowrap text-muted-foreground uppercase"
                            >
                                {{ h }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="servers.data.length === 0">
                            <td
                                colspan="5"
                                class="px-3 py-10 text-center text-muted-foreground"
                                data-server-empty
                            >
                                No servers yet. Add one to install the agent.
                            </td>
                        </tr>
                        <tr
                            v-for="row in servers.data"
                            :key="row.id"
                            class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                            :data-server-row="row.id"
                        >
                            <td class="px-3 py-2.5">
                                <Link
                                    :href="ServerController.show.url(row.id)"
                                    class="font-medium hover:underline"
                                    data-server-link
                                >
                                    {{ row.name }}
                                </Link>
                                <div
                                    v-if="row.hostname"
                                    class="font-mono-tabular text-[11.5px] text-muted-foreground"
                                >
                                    {{ row.hostname }}
                                </div>
                            </td>
                            <td class="px-3 py-2.5">
                                <ServerStatusDot
                                    :status="row.status"
                                    :label="row.status_label"
                                />
                            </td>
                            <td class="px-3 py-2.5 whitespace-nowrap">
                                <TimeStamp
                                    v-if="row.last_report_at"
                                    :iso="row.last_report_at"
                                    live
                                    class="text-[12px] text-muted-foreground"
                                />
                                <span v-else class="text-fg-subtle">Never</span>
                            </td>
                            <td class="px-3 py-2.5 whitespace-nowrap">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono-tabular">{{
                                        row.agent_version ?? '—'
                                    }}</span>
                                    <Pill
                                        v-if="row.agent_outdated"
                                        variant="warn"
                                        data-server-outdated
                                        >Outdated</Pill
                                    >
                                </div>
                            </td>
                            <td class="px-3 py-2.5">
                                <span
                                    :class="
                                        cn(
                                            'font-mono-tabular',
                                            row.open_incidents_count > 0
                                                ? 'font-medium text-destructive'
                                                : 'text-muted-foreground',
                                        )
                                    "
                                    data-server-open-incidents
                                    >{{ row.open_incidents_count }}</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div
            v-if="servers.meta.last_page > 1"
            class="flex flex-wrap items-center justify-between gap-2"
        >
            <p class="text-sm text-muted-foreground">
                Page {{ servers.meta.current_page }} of
                {{ servers.meta.last_page }} —
                <span class="font-mono-tabular">{{ servers.meta.total }}</span>
                servers
            </p>
            <div class="flex flex-wrap gap-1">
                <Button
                    v-for="link in servers.links"
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

        <WatcherSettings :watcher="watcher" />
    </div>
</template>
