<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowUpCircle,
    Briefcase,
    HeartPulse,
    Pencil,
    Power,
    RefreshCw,
    Trash2,
} from 'lucide-vue-next';
import ServiceConnectionController from '@/actions/App/Http/Controllers/Admin/ServiceConnectionController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { ServiceConnectionResource } from '@/typefinder/resources/ServiceConnectionResource';

type Connection = ServiceConnectionResource;

defineProps<{
    connections: Connection[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: '#' },
            {
                title: 'Connections',
                href: ServiceConnectionController.index.url(),
            },
        ],
    },
});

function typeLabel(type: Connection['type']): string {
    return type.charAt(0).toUpperCase() + type.slice(1);
}

function statusBadgeVariant(
    connection: Connection,
): 'default' | 'destructive' | 'secondary' | 'outline' {
    if (!connection.is_active) {
        return 'secondary';
    }

    switch (connection.health_status) {
        case 'healthy':
            return 'default';
        case 'unhealthy':
            return 'destructive';
        default:
            return 'outline';
    }
}

function statusLabel(connection: Connection): string {
    if (!connection.is_active) {
        return 'Inactive';
    }

    switch (connection.health_status) {
        case 'healthy':
            return 'Healthy';
        case 'unhealthy':
            return 'Unhealthy';
        default:
            return 'Unknown';
    }
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

    <div class="space-y-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold tracking-tight">
                    Service Connections
                </h2>
                <p class="text-muted-foreground">
                    Manage your external service integrations.
                </p>
            </div>
            <Link :href="ServiceConnectionController.create.url()">
                <Button>Add Connection</Button>
            </Link>
        </div>

        <TooltipProvider :delay-duration="200">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>URL</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Last Seen</TableHead>
                        <TableHead>Version</TableHead>
                        <TableHead class="text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="connection in connections"
                        :key="connection.id"
                    >
                        <TableCell class="font-medium">{{
                            connection.name
                        }}</TableCell>
                        <TableCell>
                            <Badge variant="outline">{{
                                typeLabel(connection.type)
                            }}</Badge>
                        </TableCell>
                        <TableCell class="text-muted-foreground">{{
                            connection.url
                        }}</TableCell>
                        <TableCell>
                            <Badge :variant="statusBadgeVariant(connection)">
                                {{ statusLabel(connection) }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-muted-foreground">
                            {{ connection.last_seen_human ?? 'Never' }}
                        </TableCell>
                        <TableCell>
                            <div class="flex flex-col gap-1 text-sm">
                                <span class="font-mono">{{
                                    connection.version ?? '—'
                                }}</span>
                                <span
                                    v-if="connection.update_available"
                                    class="inline-flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400"
                                >
                                    <ArrowUpCircle class="size-3" />
                                    {{ connection.latest_version }} available
                                </span>
                                <span
                                    v-else-if="
                                        connection.latest_version &&
                                        !connection.version
                                    "
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    latest: {{ connection.latest_version }}
                                </span>
                            </div>
                        </TableCell>
                        <TableCell class="text-right">
                            <div class="inline-flex items-center gap-1">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Link
                                            :href="
                                                ServiceConnectionController.edit.url(
                                                    connection.id,
                                                )
                                            "
                                        >
                                            <Button variant="ghost" size="icon">
                                                <Pencil class="size-4" />
                                                <span class="sr-only"
                                                    >Edit</span
                                                >
                                            </Button>
                                        </Link>
                                    </TooltipTrigger>
                                    <TooltipContent>Edit</TooltipContent>
                                </Tooltip>

                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            @click="
                                                toggleConnection(connection)
                                            "
                                        >
                                            <Power class="size-4" />
                                            <span class="sr-only">{{
                                                connection.is_active
                                                    ? 'Disable'
                                                    : 'Enable'
                                            }}</span>
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>{{
                                        connection.is_active
                                            ? 'Disable'
                                            : 'Enable'
                                    }}</TooltipContent>
                                </Tooltip>

                                <Tooltip>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <TooltipTrigger as-child>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                >
                                                    <Briefcase class="size-4" />
                                                    <span class="sr-only"
                                                        >Jobs</span
                                                    >
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                Jobs
                                            </TooltipContent>
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
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </Tooltip>

                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="text-destructive hover:text-destructive"
                                            @click="
                                                deleteConnection(connection)
                                            "
                                        >
                                            <Trash2 class="size-4" />
                                            <span class="sr-only">Delete</span>
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>Delete</TooltipContent>
                                </Tooltip>
                            </div>
                        </TableCell>
                    </TableRow>
                    <TableRow v-if="connections.length === 0">
                        <TableCell
                            :colspan="7"
                            class="py-8 text-center text-muted-foreground"
                        >
                            No connections configured yet. Add one to get
                            started.
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </TooltipProvider>
    </div>
</template>
