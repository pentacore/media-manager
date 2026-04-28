<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowUp,
    Calendar,
    Check,
    HardDrive,
    RefreshCcw,
    Server,
    X,
} from 'lucide-vue-next';
import { computed, onMounted } from 'vue';
import ServiceHealthController from '@/actions/App/Http/Controllers/Monitoring/ServiceHealthController';
import { Pill, StatCard, StatusPill, SvcChip } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { useServiceHealth } from '@/composables/useServiceHealth';
import { dashboard } from '@/routes';
import type { ServiceConnectionResource } from '@/typefinder/resources/ServiceConnectionResource';

interface DiskSpace {
    path: string | null;
    label: string | null;
    free_space: number | null;
    total_space: number | null;
}

interface Indexer {
    id: number;
    name: string;
    enable: boolean;
}

type Connection = ServiceConnectionResource;

const props = defineProps<{
    connections: Connection[];
    diskSpace?: Record<number, DiskSpace[]>;
    prowlarrIndexers?: Record<number, Indexer[]>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Live', href: dashboard().url },
            { title: 'Service Health', href: ServiceHealthController().url },
        ],
    },
});

const {
    services: liveServices,
    versions: liveVersions,
    lifecycle: liveLifecycle,
    deletedIds,
    subscribe,
} = useServiceHealth();

onMounted(subscribe);

const mergedConnections = computed<Connection[]>(() =>
    props.connections
        .filter((connection) => !deletedIds.has(connection.id))
        .map((connection) => {
            const lifecycle = liveLifecycle[connection.id];

            if (lifecycle) {
                return {
                    ...connection,
                    type: lifecycle.type as Connection['type'],
                    name: lifecycle.name,
                    url: lifecycle.url,
                    is_active: lifecycle.is_active,
                    health_status:
                        lifecycle.health_status as Connection['health_status'],
                    health_message: lifecycle.health_message,
                    version: lifecycle.version,
                    latest_version: lifecycle.latest_version,
                    update_available: lifecycle.update_available,
                    last_seen_at: lifecycle.last_seen_at,
                };
            }

            const live = liveServices[connection.id];
            const version = liveVersions[connection.id];

            return {
                ...connection,
                health_status:
                    (live?.status as Connection['health_status']) ??
                    connection.health_status,
                health_message: live?.message ?? connection.health_message,
                last_seen_at: live?.last_seen_at ?? connection.last_seen_at,
                version: version?.version ?? connection.version,
                latest_version:
                    version?.latest_version ?? connection.latest_version,
                update_available:
                    version?.update_available ?? connection.update_available,
            };
        }),
);

const healthyCount = computed(
    () =>
        mergedConnections.value.filter(
            (c) => c.is_active && c.health_status === 'healthy',
        ).length,
);

const unhealthyCount = computed(
    () =>
        mergedConnections.value.filter(
            (c) => c.is_active && c.health_status === 'unhealthy',
        ).length,
);

const updateAvailableCount = computed(
    () =>
        mergedConnections.value.filter((c) => c.update_available).length,
);

function formatSize(bytes: number | null): string {
    if (bytes === null || bytes === undefined) {
return '—';
}

    if (bytes === 0) {
return '0 B';
}

    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    const value = bytes / Math.pow(1024, i);

    return `${value.toFixed(1)} ${units[i]}`;
}

function formatTime(iso: string | null): string {
    if (!iso) {
return 'never';
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

function svcId(type: string): string {
    const t = type.toLowerCase();

    if (t.includes('jellyseerr') || t.includes('seerr')) {
return 'seerr';
}

    return t;
}

function diskFree(connectionId: number): { free: number; total: number } | null {
    const disks = props.diskSpace?.[connectionId];

    if (!disks || disks.length === 0) {
return null;
}

    let free = 0;
    let total = 0;

    for (const disk of disks) {
        free += disk.free_space ?? 0;
        total += disk.total_space ?? 0;
    }

    return { free, total };
}

function indexersFor(connectionId: number): Indexer[] | undefined {
    return props.prowlarrIndexers?.[connectionId];
}
</script>

<template>
    <Head title="Service Health" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero -->
        <div class="flex items-end justify-between gap-3">
            <div>
                <h1
                    class="text-[22px] leading-tight font-semibold tracking-tight"
                >
                    Service health
                </h1>
                <p class="mt-1 text-[13px] text-muted-foreground">
                    Pings every 5 minutes · history retained 30 days
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button variant="outline" size="sm" class="h-7 gap-1.5 text-xs">
                    <Calendar class="size-3.5" />Last 24h
                </Button>
                <Button size="sm" class="h-7 gap-1.5 text-xs">
                    <RefreshCcw class="size-3.5" />Run check now
                </Button>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <StatCard
                label="Healthy"
                :value="`${healthyCount} / ${mergedConnections.length}`"
                :hint="`${unhealthyCount} unhealthy`"
            />
            <StatCard
                label="Active services"
                :value="
                    mergedConnections.filter((c) => c.is_active).length
                "
                :hint="`${mergedConnections.filter((c) => !c.is_active).length} inactive`"
            />
            <StatCard
                label="Updates pending"
                :value="updateAvailableCount"
                hint="latest releases observed"
            />
            <StatCard
                label="Connections"
                :value="mergedConnections.length"
                :hint="`${mergedConnections.filter((c) => c.type === 'prowlarr').length} indexer hubs`"
            />
        </div>

        <!-- Service strip -->
        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <div
                class="flex items-center justify-between border-b border-border px-4 py-3"
            >
                <span
                    class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                    >Per-service status</span
                >
                <span class="text-xs text-muted-foreground"
                    >health-strip placeholder until service_metrics
                    table</span
                >
            </div>

            <div
                v-if="mergedConnections.length === 0"
                class="flex flex-col items-center gap-2 py-10 text-fg-subtle"
            >
                <Server class="size-5" />
                <span class="text-sm"
                    >No service connections configured.</span
                >
            </div>

            <div v-else>
                <div
                    v-for="connection in mergedConnections"
                    :key="connection.id"
                    class="border-b border-border px-4 py-3 last:border-b-0"
                >
                    <div
                        class="grid items-center gap-4"
                        style="grid-template-columns: 200px 1fr 120px 120px 120px"
                    >
                        <div>
                            <div class="flex items-center gap-2">
                                <span
                                    v-if="
                                        connection.is_active &&
                                        connection.health_status === 'healthy'
                                    "
                                    class="text-success"
                                >
                                    <Check class="size-4" />
                                </span>
                                <span
                                    v-else-if="
                                        connection.is_active &&
                                        connection.health_status === 'unhealthy'
                                    "
                                    class="text-destructive"
                                >
                                    <X class="size-4" />
                                </span>
                                <span v-else class="text-warning">
                                    <AlertTriangle class="size-4" />
                                </span>
                                <SvcChip
                                    :id="svcId(connection.type)"
                                    :label="connection.name"
                                />
                            </div>
                            <div
                                class="font-mono-tabular mt-1 truncate text-[11px] text-fg-subtle"
                            >
                                {{ connection.url }}
                            </div>
                        </div>

                        <!-- 60-min strip placeholder -->
                        <div
                            class="flex h-7 items-end gap-px"
                            aria-hidden="true"
                        >
                            <span
                                v-for="i in 60"
                                :key="`bar-${connection.id}-${i}`"
                                class="w-1 rounded-sm"
                                :class="
                                    connection.health_status === 'healthy'
                                        ? 'bg-accent/70'
                                        : connection.health_status ===
                                            'unhealthy'
                                          ? 'bg-destructive/70'
                                          : 'bg-warning/70'
                                "
                                :style="{ height: `${30 + (i % 7) * 10}%` }"
                            />
                        </div>

                        <div>
                            <div
                                class="text-[10.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Last seen
                            </div>
                            <div class="font-mono-tabular text-[12px]">
                                {{ formatTime(connection.last_seen_at) }}
                            </div>
                        </div>

                        <div>
                            <div
                                class="text-[10.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Version
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="font-mono-tabular text-[12px]">{{
                                    connection.version ?? '—'
                                }}</span>
                                <Pill
                                    v-if="connection.update_available"
                                    variant="warn"
                                    class="text-[10px]"
                                >
                                    <ArrowUp class="size-2.5" />{{
                                        connection.latest_version ?? 'update'
                                    }}
                                </Pill>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <StatusPill
                                v-if="connection.is_active"
                                :status="connection.health_status"
                            />
                            <Pill v-else>inactive</Pill>
                        </div>
                    </div>

                    <!-- Health message -->
                    <div
                        v-if="
                            connection.is_active &&
                            connection.health_status === 'unhealthy' &&
                            connection.health_message
                        "
                        class="mt-2 rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 font-mono text-[11.5px] text-destructive"
                    >
                        {{ connection.health_message }}
                    </div>

                    <!-- Disk + indexer rows -->
                    <div
                        v-if="diskFree(connection.id)"
                        class="mt-2 flex items-center gap-2 text-[11.5px] text-muted-foreground"
                    >
                        <HardDrive class="size-3.5" />
                        <span class="font-mono-tabular">{{
                            formatSize(diskFree(connection.id)!.free)
                        }}</span>
                        <span>free of</span>
                        <span class="font-mono-tabular">{{
                            formatSize(diskFree(connection.id)!.total)
                        }}</span>
                    </div>

                    <div
                        v-if="
                            connection.type === 'prowlarr' &&
                            indexersFor(connection.id)
                        "
                        class="mt-2 flex flex-wrap items-center gap-1.5"
                    >
                        <span class="text-[11.5px] text-muted-foreground"
                            >Indexers:</span
                        >
                        <Pill
                            v-for="indexer in indexersFor(connection.id)"
                            :key="`${connection.id}-${indexer.id}`"
                            :variant="indexer.enable ? 'ok' : 'default'"
                            class="text-[10.5px]"
                        >
                            {{ indexer.name }}
                        </Pill>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
