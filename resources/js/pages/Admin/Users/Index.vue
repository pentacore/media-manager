<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3'
import UserController from '@/actions/App/Http/Controllers/Admin/UserController'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select'
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table'
import { computed } from 'vue'

interface UserItem {
    id: number
    name: string
    email: string
    role: { value: string; label?: string } | string
    sso_provider: string | null
    avatar_url: string | null
    created_at: string
}

defineProps<{
    users: UserItem[]
}>()

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: '#' },
            { title: 'Users', href: UserController.index.url() },
        ],
    },
})

const page = usePage()
const currentUserId = computed(() => page.props.auth.user?.id)

function roleValue(role: UserItem['role']): string {
    return typeof role === 'string' ? role : role.value
}

function roleLabel(role: UserItem['role']): string {
    if (typeof role === 'string') {
        return role.charAt(0).toUpperCase() + role.slice(1)
    }
    return role.label ?? role.value
}

function authMethod(ssoProvider: string | null): string {
    if (!ssoProvider) return 'Local'
    return ssoProvider.charAt(0).toUpperCase() + ssoProvider.slice(1)
}

function initials(name: string): string {
    return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

function updateRole(user: UserItem, newRole: string) {
    router.visit(UserController.updateRole.url(user.id), {
        method: 'patch',
        data: { role: newRole },
    })
}

function deleteUser(user: UserItem) {
    if (confirm(`Delete ${user.name}? This cannot be undone.`)) {
        router.visit(UserController.destroy.url(user.id), {
            method: 'delete',
        })
    }
}
</script>

<template>
    <Head title="User Management" />

    <div class="space-y-6 p-6">
        <div>
            <h2 class="text-2xl font-bold tracking-tight">Users</h2>
            <p class="text-muted-foreground">Manage user accounts and roles.</p>
        </div>

        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>User</TableHead>
                    <TableHead>Email</TableHead>
                    <TableHead>Role</TableHead>
                    <TableHead>Auth Method</TableHead>
                    <TableHead>Joined</TableHead>
                    <TableHead class="text-right">Actions</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="user in users" :key="user.id">
                    <TableCell>
                        <div class="flex items-center gap-3">
                            <Avatar class="h-8 w-8">
                                <AvatarImage v-if="user.avatar_url" :src="user.avatar_url" :alt="user.name" />
                                <AvatarFallback>{{ initials(user.name) }}</AvatarFallback>
                            </Avatar>
                            <span class="font-medium">{{ user.name }}</span>
                        </div>
                    </TableCell>
                    <TableCell class="text-muted-foreground">{{ user.email }}</TableCell>
                    <TableCell>
                        <Select
                            v-if="user.id !== currentUserId"
                            :default-value="roleValue(user.role)"
                            @update:model-value="(val: string) => updateRole(user, val)"
                        >
                            <SelectTrigger class="w-28">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="admin">Admin</SelectItem>
                                <SelectItem value="member">Member</SelectItem>
                                <SelectItem value="viewer">Viewer</SelectItem>
                            </SelectContent>
                        </Select>
                        <Badge v-else variant="outline">{{ roleLabel(user.role) }}</Badge>
                    </TableCell>
                    <TableCell>
                        <Badge variant="secondary">{{ authMethod(user.sso_provider) }}</Badge>
                    </TableCell>
                    <TableCell class="text-muted-foreground">{{ user.created_at }}</TableCell>
                    <TableCell class="text-right">
                        <Button
                            v-if="user.id !== currentUserId"
                            variant="ghost"
                            size="sm"
                            class="text-destructive"
                            @click="deleteUser(user)"
                        >
                            Delete
                        </Button>
                        <span v-else class="text-xs text-muted-foreground">You</span>
                    </TableCell>
                </TableRow>
                <TableRow v-if="users.length === 0">
                    <TableCell :colspan="6" class="text-center text-muted-foreground py-8">
                        No users found.
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </div>
</template>
