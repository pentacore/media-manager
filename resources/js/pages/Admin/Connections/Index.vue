<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowUp,
    HeartPulse,
    MoreHorizontal,
    Pencil,
    Plus,
    Power,
    RefreshCw,
    Trash2,
} from '@lucide/vue';
import { computed, onMounted } from 'vue';
import { Pill, StatusPill, SvcChip } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useServiceHealth } from '@/composables/useServiceHealth';
import ServiceConnectionController from '@/actions/App/Http/Controllers/Admin/ServiceConnectionController';
import { dashboard } from '@/routes';
import type { ServiceConnectionResource } from '@/typefinder/resources/ServiceConnectionResource';

type Connection = ServiceConnectionResource;

const props = defineProps<{
    connections: Connection[];
}>();

const {
    services: liveServices,
    versions: liveVersions,
    lifecycle: liveLifecycle,
    deletedIds,
    subscribe,
} = useServiceHealth();

onMounted(subscribe);

const liveConnections = computed<Connection[]>(() => {
    const merged = props.connections
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

            const status = liveServices[connection.id];
            const version = liveVersions[connection.id];

            return {
                ...connection,
                health_status:
                    (status?.status as Connection['health_status']) ??
                    connection.health_status,
                last_seen_at: status?.last_seen_at ?? connection.last_seen_at,
                version: version?.version ?? connection.version,
                latest_version:
                    version?.latest_version ?? connection.latest_version,
                update_available:
                    version?.update_available ?? connection.update_available,
            };
        });

    const knownIds = new Set(props.connections.map((c) => c.id));

    for (const id of Object.keys(liveLifecycle)) {
        const numericId = Number(id);

        if (knownIds.has(numericId) || deletedIds.has(numericId)) {
            continue;
        }

        const lifecycle = liveLifecycle[numericId];
        merged.unshift({
            ...lifecycle,
            type: lifecycle.type as Connection['type'],
            health_status:
                lifecycle.health_status as Connection['health_status'],
            last_seen_human: null,
        } as Connection);
    }

    return merged;
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            {
                title: 'Connections',
                href: ServiceConnectionController.index.url(),
            },
        ],
    },
});

function svcId(type: Connection['type']): string {
    const t = String(type).toLowerCase();

    if (t.includes('jellyseerr') || t.includes('seerr')) {
        return 'seerr';
    }

    return t;
}

function statusKey(connection: Connection): string {
    if (!connection.is_active) {
        return 'inactive';
    }

    return connection.health_status ?? 'unknown';
}

function toggleConnection(connection: Connection) {
    router.visit(ServiceConnectionController.toggle.url(connection.id), {
        method: 'patch',
        preserveScroll: true,
    });
}

function deleteConnection(connection: Connection) {
    if (confirm(`Delete ${connection.name}? This cannot be undone.`)) {
        router.visit(ServiceConnectionController.destroy.url(connection.id), {
            method: 'delete',
        });
    }
}

function checkHealth(connection: Connection) {
    router.visit(ServiceConnectionController.checkHealth.url(connection.id), {
        method: 'post',
        preserveScroll: true,
    });
}

function checkVersion(connection: Connection) {
    router.visit(ServiceConnectionController.checkVersion.url(connection.id), {
        method: 'post',
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Service Connections" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero -->
        <div class="flex items-end justify-between gap-3">
            <div>
                <div class="mb-1.5 text-[13px] text-muted-foreground">
                    Admin
                    <span class="text-fg-subtle">/</span>
                    Connections
                </div>
                <h1
                    class="text-[22px] leading-tight font-semibold tracking-tight"
                >
                    Service connections
                </h1>
                <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                    One row per upstream service. Webhook URLs and tokens are
                    auto-generated; rotate by clearing the field on edit.
                </p>
            </div>
            <Link :href="ServiceConnectionController.create.url()">
                <Button size="sm" class="h-7 gap-1.5 text-xs">
                    <Plus class="size-3.5" />Add connection
                </Button>
            </Link>
        </div>

        <!-- Connections table -->
        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th
                                v-for="h in [
                                    'Service',
                                    'URL',
                                    'Health',
                                    'Last seen',
                                    'API key',
                                    '',
                                ]"
                                :key="h"
                                class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                {{ h }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="connection in liveConnections"
                            :key="connection.id"
                            class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                        >
                            <td class="px-3 py-2.5">
                                <span class="flex items-center gap-2.5">
                                    <SvcChip
                                        :id="svcId(connection.type)"
                                        :label="connection.name"
                                    />
                                    <span
                                        class="font-mono-tabular text-[11px] text-fg-subtle"
                                        >v{{ connection.version ?? '—' }}</span
                                    >
                                    <Pill
                                        v-if="connection.update_available"
                                        variant="warn"
                                        class="text-[10px]"
                                    >
                                        <ArrowUp class="size-2.5" />{{
                                            connection.latest_version ??
                                            'update'
                                        }}
                                    </Pill>
                                </span>
                            </td>
                            <td
                                class="font-mono-tabular px-3 py-2.5 text-[12px] text-muted-foreground"
                            >
                                {{ connection.url }}
                            </td>
                            <td class="px-3 py-2.5">
                                <StatusPill :status="statusKey(connection)" />
                            </td>
                            <td
                                class="font-mono-tabular px-3 py-2.5 text-[11.5px] text-fg-subtle"
                            >
                                {{ connection.last_seen_human ?? 'never' }}
                            </td>
                            <td class="px-3 py-2.5">
                                <Pill
                                    :variant="
                                        connection.api_key_set ? 'ok' : 'warn'
                                    "
                                    :dot="connection.api_key_set"
                                >
                                    {{
                                        connection.api_key_set
                                            ? 'Set'
                                            : 'Missing'
                                    }}
                                </Pill>
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <Link
                                        :href="
                                            ServiceConnectionController.edit.url(
                                                connection.id,
                                            )
                                        "
                                    >
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="h-7 px-2 text-xs"
                                        >
                                            <Pencil class="size-3.5" />Edit
                                        </Button>
                                    </Link>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="h-7 px-2 text-xs"
                                        @click="checkHealth(connection)"
                                    >
                                        Test
                                    </Button>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                class="size-7 p-0"
                                            >
                                                <MoreHorizontal
                                                    class="size-3.5"
                                                />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            class="w-48"
                                        >
                                            <DropdownMenuLabel
                                                >Jobs</DropdownMenuLabel
                                            >
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                @click="checkHealth(connection)"
                                            >
                                                <HeartPulse
                                                    class="mr-2 size-4"
                                                />
                                                Check health
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                @click="
                                                    checkVersion(connection)
                                                "
                                            >
                                                <RefreshCw
                                                    class="mr-2 size-4"
                                                />
                                                Check version
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                @click="
                                                    toggleConnection(connection)
                                                "
                                            >
                                                <Power class="mr-2 size-4" />
                                                {{
                                                    connection.is_active
                                                        ? 'Disable'
                                                        : 'Enable'
                                                }}
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                class="text-destructive focus:text-destructive"
                                                @click="
                                                    deleteConnection(connection)
                                                "
                                            >
                                                <Trash2 class="mr-2 size-4" />
                                                Delete
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="liveConnections.length === 0">
                            <td
                                colspan="6"
                                class="px-3 py-8 text-center text-sm text-fg-subtle"
                            >
                                No connections configured. Add one to get
                                started.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
