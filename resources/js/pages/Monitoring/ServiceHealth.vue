<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    ArrowUp,
    CircleCheck,
    CircleHelp,
    CircleX,
    HardDrive,
    HeartPulse,
    Server,
} from 'lucide-vue-next';
import { computed, onMounted } from 'vue';
import ServiceHealthController from '@/actions/App/Http/Controllers/Monitoring/ServiceHealthController';
import type { ServiceConnectionResource } from '@/typefinder/resources/ServiceConnectionResource';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useServiceHealth } from '@/composables/useServiceHealth';
import { dashboard } from '@/routes';

interface DiskSpace {
    path: string | null;
    label: string | null;
    free_space: number | null;
    total_space: number | null;
}

type Connection = ServiceConnectionResource;

const props = defineProps<{
    connections: Connection[];
    diskSpace?: Record<number, DiskSpace[]>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Service Health', href: ServiceHealthController().url },
        ],
    },
});

const { services: liveServices, subscribe } = useServiceHealth();

onMounted(() => {
    subscribe();
});

const mergedConnections = computed<Connection[]>(() =>
    props.connections.map((connection) => {
        const live = liveServices[connection.id];

        if (live) {
            return {
                ...connection,
                health_status: live.status as Connection['health_status'],
                last_seen_at: live.last_seen_at,
            };
        }

        return connection;
    }),
);

const healthyCount = computed(
    () =>
        mergedConnections.value.filter(
            (c) => c.is_active && c.health_status === 'healthy',
        ).length,
);

function typeLabel(type: string): string {
    return type.charAt(0).toUpperCase() + type.slice(1);
}

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

    const date = new Date(iso);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);

    if (diffMins < 1) {
        return 'just now';
    }

    if (diffMins < 60) {
        return `${diffMins} min ago`;
    }

    const diffHours = Math.floor(diffMins / 60);

    if (diffHours < 24) {
        return `${diffHours}h ago`;
    }

    const diffDays = Math.floor(diffHours / 24);

    return `${diffDays}d ago`;
}

function healthBadgeVariant(
    connection: Connection,
): 'default' | 'destructive' | 'secondary' {
    if (!connection.is_active) {
        return 'secondary';
    }

    if (connection.health_status === 'healthy') {
        return 'default';
    }

    if (connection.health_status === 'unhealthy') {
        return 'destructive';
    }

    return 'secondary';
}

function healthLabel(connection: Connection): string {
    if (!connection.is_active) {
        return 'Inactive';
    }

    return (
        connection.health_status.charAt(0).toUpperCase() +
        connection.health_status.slice(1)
    );
}

function diskSpaceFor(connectionId: number): DiskSpace[] | undefined {
    return props.diskSpace?.[connectionId];
}
</script>

<template>
    <Head title="Service Health" />

    <div class="space-y-6 p-6">
        <div class="flex items-center gap-3">
            <HeartPulse class="size-6 text-muted-foreground" />
            <div>
                <h2 class="text-2xl font-bold tracking-tight">
                    Service Health
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ mergedConnections.length }} service{{
                        mergedConnections.length === 1 ? '' : 's'
                    }}, {{ healthyCount }} healthy
                </p>
            </div>
        </div>

        <div
            v-if="mergedConnections.length === 0"
            class="flex flex-col items-center justify-center py-16 text-center"
        >
            <Server class="mb-3 size-10 text-muted-foreground/50" />
            <p class="text-sm text-muted-foreground">
                No service connections configured.
            </p>
        </div>

        <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <Card v-for="connection in mergedConnections" :key="connection.id">
                <CardHeader>
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <CardTitle class="truncate">{{
                                connection.name
                            }}</CardTitle>
                            <CardDescription class="truncate">{{
                                connection.url
                            }}</CardDescription>
                        </div>
                        <Badge variant="outline">{{
                            typeLabel(connection.type)
                        }}</Badge>
                    </div>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div class="flex items-center gap-2">
                        <CircleCheck
                            v-if="
                                connection.is_active &&
                                connection.health_status === 'healthy'
                            "
                            class="size-4 text-green-600 dark:text-green-400"
                        />
                        <CircleX
                            v-else-if="
                                connection.is_active &&
                                connection.health_status === 'unhealthy'
                            "
                            class="size-4 text-destructive"
                        />
                        <CircleHelp
                            v-else
                            class="size-4 text-muted-foreground"
                        />
                        <Badge :variant="healthBadgeVariant(connection)">
                            {{ healthLabel(connection) }}
                        </Badge>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="text-muted-foreground">Version:</span>
                        <span class="font-medium">{{
                            connection.version ?? '—'
                        }}</span>
                        <Badge
                            v-if="connection.update_available"
                            variant="outline"
                            class="gap-1"
                        >
                            <ArrowUp class="size-3" />
                            Update available{{
                                connection.latest_version
                                    ? ` → ${connection.latest_version}`
                                    : ''
                            }}
                        </Badge>
                    </div>

                    <div class="text-xs text-muted-foreground">
                        Last seen: {{ formatTime(connection.last_seen_at) }}
                    </div>

                    <div
                        v-if="
                            !props.diskSpace &&
                            (connection.type === 'sonarr' ||
                                connection.type === 'radarr') &&
                            connection.is_active
                        "
                        class="flex items-center gap-2 border-t pt-3 text-xs text-muted-foreground"
                    >
                        <HardDrive class="size-3" />
                        Loading disk space…
                    </div>
                    <div
                        v-else-if="
                            diskSpaceFor(connection.id) &&
                            diskSpaceFor(connection.id)!.length > 0
                        "
                        class="space-y-1 border-t pt-3"
                    >
                        <div
                            class="flex items-center gap-2 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                        >
                            <HardDrive class="size-3" />
                            Disk Space
                        </div>
                        <div
                            v-for="(disk, index) in diskSpaceFor(connection.id)"
                            :key="`${connection.id}-disk-${index}`"
                            class="flex items-center justify-between text-sm"
                        >
                            <span class="truncate text-muted-foreground">
                                {{ disk.label ?? disk.path ?? 'Unknown' }}
                            </span>
                            <span class="shrink-0 tabular-nums">
                                {{ formatSize(disk.free_space) }} /
                                {{ formatSize(disk.total_space) }}
                            </span>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
