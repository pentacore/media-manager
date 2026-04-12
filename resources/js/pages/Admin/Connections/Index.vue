<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import ServiceConnectionController from '@/actions/App/Http/Controllers/Admin/ServiceConnectionController'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table'

interface Connection {
    id: number
    type: { value: string; label?: string } | string
    name: string
    url: string
    is_active: boolean
    last_seen_at: string | null
    version: string | null
}

defineProps<{
    connections: Connection[]
}>()

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: '#' },
            { title: 'Connections', href: ServiceConnectionController.index.url() },
        ],
    },
})

function typeLabel(type: Connection['type']): string {
    if (typeof type === 'string') {
        return type.charAt(0).toUpperCase() + type.slice(1)
    }
    return type.label ?? type.value
}

function toggleConnection(connection: Connection) {
    router.visit(ServiceConnectionController.toggle.url(connection.id), {
        method: 'patch',
    })
}

function deleteConnection(connection: Connection) {
    if (confirm(`Delete ${connection.name}? This cannot be undone.`)) {
        router.visit(ServiceConnectionController.destroy.url(connection.id), {
            method: 'delete',
        })
    }
}
</script>

<template>
    <Head title="Service Connections" />

    <div class="space-y-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold tracking-tight">Service Connections</h2>
                <p class="text-muted-foreground">Manage your external service integrations.</p>
            </div>
            <Link :href="ServiceConnectionController.create.url()">
                <Button>Add Connection</Button>
            </Link>
        </div>

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
                <TableRow v-for="connection in connections" :key="connection.id">
                    <TableCell class="font-medium">{{ connection.name }}</TableCell>
                    <TableCell>
                        <Badge variant="outline">{{ typeLabel(connection.type) }}</Badge>
                    </TableCell>
                    <TableCell class="text-muted-foreground">{{ connection.url }}</TableCell>
                    <TableCell>
                        <Badge :variant="connection.is_active ? 'default' : 'secondary'">
                            {{ connection.is_active ? 'Active' : 'Inactive' }}
                        </Badge>
                    </TableCell>
                    <TableCell class="text-muted-foreground">
                        {{ connection.last_seen_at ?? 'Never' }}
                    </TableCell>
                    <TableCell class="text-muted-foreground">
                        {{ connection.version ?? '-' }}
                    </TableCell>
                    <TableCell class="text-right space-x-2">
                        <Link :href="ServiceConnectionController.edit.url(connection.id)">
                            <Button variant="ghost" size="sm">Edit</Button>
                        </Link>
                        <Button variant="ghost" size="sm" @click="toggleConnection(connection)">
                            {{ connection.is_active ? 'Disable' : 'Enable' }}
                        </Button>
                        <Button variant="ghost" size="sm" class="text-destructive" @click="deleteConnection(connection)">
                            Delete
                        </Button>
                    </TableCell>
                </TableRow>
                <TableRow v-if="connections.length === 0">
                    <TableCell :colspan="7" class="text-center text-muted-foreground py-8">
                        No connections configured yet. Add one to get started.
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </div>
</template>
