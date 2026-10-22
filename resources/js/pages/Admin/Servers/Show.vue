<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { KeyRound, Trash2 } from '@lucide/vue';
import { onMounted } from 'vue';
import ServerController from '@/actions/App/Http/Controllers/Admin/ServerController';
import { PageHeader } from '@/components/mm';
import {
    CheckList,
    EnrollmentDialog,
    IncidentList,
    MetricsPanel,
    ServerFacts,
    ServerStatusDot,
} from '@/components/servers';
import type {
    EnumOption,
    LatestReport,
    MetricSeries,
    ServerCheckRow,
    ServerDetail,
    ServerIncidentRow,
    ServerMetricRange,
} from '@/components/servers';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { useConfirm } from '@/composables/useConfirm';
import { useRealtimeReload } from '@/composables/useRealtimeReload';
import { dashboard } from '@/routes';
import type { DbPingEngine, HostCheckType } from '@/typefinder';

const props = defineProps<{
    server: ServerDetail;
    latest: LatestReport | null;
    checks: ServerCheckRow[];
    checkTypes: EnumOption<HostCheckType>[];
    dbEngines: EnumOption<DbPingEngine>[];
    incidents: ServerIncidentRow[];
    range: ServerMetricRange;
    ranges: EnumOption<ServerMetricRange>[];
    metrics?: MetricSeries;
}>();

const isThisServer = (event: { server_id: number }): boolean =>
    event.server_id === props.server.id;

const reports = useRealtimeReload({
    channel: 'admin.servers',
    event: 'ServerReported',
    only: ['server', 'latest', 'checks', 'incidents', 'metrics'],
    filter: isThisServer,
});

const incidentChanges = useRealtimeReload({
    channel: 'admin.servers',
    event: 'ServerIncidentChanged',
    only: ['server', 'incidents'],
    filter: isThisServer,
});

onMounted(() => {
    reports.subscribe();
    incidentChanges.subscribe();
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'Servers', href: ServerController.index.url() },
        ],
    },
});

function regenerate(): void {
    router.post(
        ServerController.regenerateEnrollment.url(props.server.id),
        {},
        { preserveScroll: true },
    );
}

const { confirm } = useConfirm();

async function remove(): Promise<void> {
    const confirmed = await confirm({
        title: `Remove "${props.server.name}"?`,
        description:
            'Its checks, incidents and metrics are deleted and its agent can no longer report. This cannot be undone.',
        confirmLabel: 'Remove',
        destructive: true,
    });

    if (!confirmed) {
        return;
    }

    router.delete(ServerController.destroy.url(props.server.id));
}
</script>

<template>
    <Head :title="server.name" />

    <div class="space-y-6 p-6">
        <PageHeader align="start" class="sm:gap-4">
            <template #title>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="min-w-0 text-[20px] font-semibold wrap-anywhere">
                        {{ server.name }}
                    </h1>
                    <ServerStatusDot
                        :status="server.status"
                        :label="server.status_label"
                    />
                </div>
            </template>
            <template #description>
                <p
                    class="mt-1 text-[12.5px] break-words text-muted-foreground"
                    data-server-description
                >
                    <template v-if="server.hostname">
                        {{
                            [server.hostname, server.os, server.arch]
                                .filter(Boolean)
                                .join(' · ')
                        }}
                    </template>
                    <template v-else>No agent has enrolled yet.</template>
                </p>
            </template>
            <template #secondaryActions>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    class="h-8 gap-1.5 text-xs"
                    data-server-regenerate
                    @click="regenerate"
                >
                    <KeyRound class="size-3.5" />New enrollment token
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="destructive"
                    class="h-8 gap-1.5 text-xs"
                    data-server-delete
                    @click="remove"
                >
                    <Trash2 class="size-3.5" />Remove server
                </Button>
            </template>
            <template #menu>
                <DropdownMenuItem
                    data-page-header-item="regenerate"
                    @select="regenerate"
                >
                    <KeyRound class="size-3.5" />New enrollment token
                </DropdownMenuItem>
                <DropdownMenuItem
                    variant="destructive"
                    data-page-header-item="delete"
                    @select="remove"
                >
                    <Trash2 class="size-3.5" />Remove server
                </DropdownMenuItem>
            </template>
        </PageHeader>

        <ServerFacts :server="server" :latest="latest" />

        <MetricsPanel
            v-if="server.last_report_at"
            :metrics="metrics"
            :range="range"
            :ranges="ranges"
        />

        <div class="grid gap-6 lg:grid-cols-2">
            <IncidentList :incidents="incidents" />
            <CheckList :checks="checks" />
        </div>

        <EnrollmentDialog :server-id="server.id" :server-name="server.name" />
    </div>
</template>
