<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowUp,
    Check,
    HardDrive,
    RefreshCcw,
    Server,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';
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
    display?: 'free' | 'used' | 'both';
}

interface Indexer {
    id: number;
    name: string;
    enable: boolean;
}

type Connection = ServiceConnectionResource;

interface MetricBucket {
    minute: number;
    status: 'healthy' | 'unhealthy' | 'unknown' | 'gap' | string;
    latency_ms: number | null;
}

interface MetricsBundle {
    strips: Record<number, MetricBucket[]>;
    uptime: Record<number, number | null>;
    avg_latency: Record<number, number | null>;
}

const props = defineProps<{
    connections: Connection[];
    metrics?: MetricsBundle;
    diskSpace?: Record<number, DiskSpace[]>;
    prowlarrIndexers?: Record<number, Indexer[]>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Live', href: dashboard().url },
            { title: 'Service Health', href: ServiceHealthController.index.url() },
        ],
    },
});

const checking = ref(false);

function runChecks(): void {
    if (checking.value) {
        return;
    }

    checking.value = true;
    router.post(
        ServiceHealthController.runChecks.url(),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                checking.value = false;
            },
        },
    );
}

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
    () => mergedConnections.value.filter((c) => c.update_available).length,
);

const overallUptime = computed<number | null>(() => {
    const samples = Object.values(props.metrics?.uptime ?? {}).filter(
        (v): v is number => typeof v === 'number',
    );

    if (samples.length === 0) {
        return null;
    }

    return samples.reduce((acc, v) => acc + v, 0) / samples.length;
});

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

// Reactive "now" so relative time labels tick without waiting for a
// websocket event. The 30s cadence matches the granularity of the
// labels we render (just now / Nm ago / Nh ago) so we never refresh
// for nothing.
const nowTick = ref(Date.now());
let nowTickTimer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    nowTickTimer = setInterval(() => {
        nowTick.value = Date.now();
    }, 30_000);
});

onUnmounted(() => {
    if (nowTickTimer !== null) {
        clearInterval(nowTickTimer);
        nowTickTimer = null;
    }
});

function formatTime(iso: string | null): string {
    if (!iso) {
        return 'never';
    }

    // Read nowTick so Vue invalidates this expression when the timer
    // fires. The actual value comes from Date.now() — the tick is just
    // there to mark the dependency.
    const ms = nowTick.value - new Date(iso).getTime();
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

function disksFor(connectionId: number): DiskSpace[] | undefined {
    return props.diskSpace?.[connectionId];
}

function diskUsed(disk: DiskSpace): number | null {
    if (disk.free_space === null || disk.total_space === null) {
        return null;
    }

    return Math.max(0, disk.total_space - disk.free_space);
}

function indexersFor(connectionId: number): Indexer[] | undefined {
    return props.prowlarrIndexers?.[connectionId];
}

const EMPTY_STRIP: MetricBucket[] = Array.from({ length: 60 }, (_, i) => ({
    minute: i,
    status: 'gap',
    latency_ms: null,
}));

function stripFor(connectionId: number): MetricBucket[] {
    return props.metrics?.strips?.[connectionId] ?? EMPTY_STRIP;
}

function avgLatencyFor(connectionId: number): number | null {
    return props.metrics?.avg_latency?.[connectionId] ?? null;
}

function bucketColor(status: string): string {
    switch (status) {
        case 'healthy':
            return 'bg-success/80';
        case 'degraded':
        case 'unknown':
            return 'bg-warning/80';
        case 'unhealthy':
            return 'bg-destructive/80';
        default:
            return 'bg-border';
    }
}

function barHeight(bucket: MetricBucket): number {
    if (bucket.status === 'gap') {
        return 20;
    }

    if (!bucket.latency_ms) {
        return 60;
    }

    // 0-500ms maps to 30-100%; clamp for outliers.
    const pct = 30 + Math.min(70, (bucket.latency_ms / 500) * 70);

    return Math.round(pct);
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
                <span
                    class="font-mono-tabular inline-flex h-7 items-center rounded-md border border-border bg-bg-elev px-2.5 text-[11.5px] text-muted-foreground"
                >
                    Strip · last 60 min
                </span>
                <Button
                    size="sm"
                    class="h-7 gap-1.5 text-xs"
                    :disabled="checking"
                    @click="runChecks"
                >
                    <RefreshCcw
                        class="size-3.5"
                        :class="{ 'animate-spin': checking }"
                    />Run check now
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
                :value="mergedConnections.filter((c) => c.is_active).length"
                :hint="`${mergedConnections.filter((c) => !c.is_active).length} inactive`"
            />
            <StatCard
                label="Updates pending"
                :value="updateAvailableCount"
                hint="latest releases observed"
            />
            <StatCard
                label="Avg uptime · 30d"
                :value="
                    overallUptime !== null
                        ? `${overallUptime.toFixed(2)}%`
                        : '—'
                "
                :hint="
                    overallUptime !== null
                        ? 'across all configured services'
                        : 'no metrics yet'
                "
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
                    >health-strip placeholder until service_metrics table</span
                >
            </div>

            <div
                v-if="mergedConnections.length === 0"
                class="flex flex-col items-center gap-2 py-10 text-fg-subtle"
            >
                <Server class="size-5" />
                <span class="text-sm">No service connections configured.</span>
            </div>

            <div v-else>
                <div
                    v-for="connection in mergedConnections"
                    :key="connection.id"
                    class="border-b border-border px-4 py-3 last:border-b-0"
                >
                    <div
                        class="grid items-center gap-4"
                        style="
                            grid-template-columns: 200px 1fr 120px 120px 120px;
                        "
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

                        <!-- 60-min strip from service_metrics -->
                        <div
                            class="flex h-7 items-end gap-px"
                            :title="`Per-minute health, oldest left → newest right`"
                            aria-hidden="true"
                        >
                            <span
                                v-for="bucket in stripFor(connection.id)"
                                :key="`bar-${connection.id}-${bucket.minute}`"
                                class="w-1 rounded-sm"
                                :class="bucketColor(bucket.status)"
                                :style="{ height: `${barHeight(bucket)}%` }"
                            />
                        </div>

                        <div>
                            <div
                                class="text-[10.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Latency · 1h
                            </div>
                            <div class="font-mono-tabular text-[12px]">
                                <span
                                    v-if="avgLatencyFor(connection.id) !== null"
                                >
                                    {{ avgLatencyFor(connection.id) }}ms
                                </span>
                                <span v-else class="text-fg-subtle">—</span>
                            </div>
                            <div
                                class="font-mono-tabular text-[10.5px] text-fg-subtle"
                            >
                                seen {{ formatTime(connection.last_seen_at) }}
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
                        v-if="
                            disksFor(connection.id) &&
                            disksFor(connection.id)!.length > 0
                        "
                        class="mt-2 flex flex-col gap-1"
                    >
                        <div
                            v-for="(disk, idx) in disksFor(connection.id)"
                            :key="`${connection.id}-disk-${idx}-${disk.path}`"
                            class="flex items-center gap-2 text-[11.5px] text-muted-foreground"
                        >
                            <HardDrive class="size-3.5" />
                            <span
                                v-if="disk.label && disk.label !== disk.path"
                                class="text-foreground"
                                >{{ disk.label }}</span
                            >
                            <span
                                v-else-if="disk.path"
                                class="font-mono-tabular text-foreground"
                                >{{ disk.path }}</span
                            >
                            <span class="text-fg-subtle">·</span>
                            <template
                                v-if="(disk.display ?? 'both') === 'free'"
                            >
                                <span class="font-mono-tabular">{{
                                    formatSize(disk.free_space)
                                }}</span>
                                <span>free</span>
                            </template>
                            <template
                                v-else-if="(disk.display ?? 'both') === 'used'"
                            >
                                <span class="font-mono-tabular">{{
                                    formatSize(diskUsed(disk))
                                }}</span>
                                <span>used</span>
                            </template>
                            <template v-else>
                                <span class="font-mono-tabular">{{
                                    formatSize(disk.free_space)
                                }}</span>
                                <span>free of</span>
                                <span class="font-mono-tabular">{{
                                    formatSize(disk.total_space)
                                }}</span>
                            </template>
                        </div>
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
