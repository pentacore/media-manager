<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { Plus, Upload } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import EmbyLinkController from '@/actions/App/Http/Controllers/Admin/EmbyLinkController';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import UserLinkController from '@/actions/App/Http/Controllers/Emby/UserLinkController';
import InputError from '@/components/InputError.vue';
import { InitialsAvatar, Pill, SvcChip } from '@/components/mm';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';

interface RoleOption {
    value: string;
    label: string;
}

interface UserItem {
    id: number;
    name: string;
    email: string;
    role: { value: string; label?: string } | string;
    sso_provider: string | null;
    avatar_url: string | null;
    created_at: string;
    emby_link_id: number | null;
    emby_username: string | null;
}

defineProps<{
    users: UserItem[];
    roles: RoleOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'Users', href: UserController.index.url() },
        ],
    },
});

const page = usePage();
const currentUserId = computed(() => page.props.auth.user?.id);
const showCreateDialog = ref(false);
const setPassword = ref(false);

function roleValue(role: UserItem['role']): string {
    return typeof role === 'string' ? role : role.value;
}

function roleLabel(role: UserItem['role']): string {
    if (typeof role === 'string') {
        return role.charAt(0).toUpperCase() + role.slice(1);
    }

    return role.label ?? role.value;
}

function rolePillVariant(
    role: UserItem['role'],
): 'ok' | 'info' | 'warn' | 'default' {
    const v = roleValue(role);

    if (v === 'admin') {
        return 'info';
    }

    if (v === 'member') {
        return 'ok';
    }

    return 'default';
}

function authMethod(ssoProvider: string | null): string {
    if (!ssoProvider) {
        return 'local';
    }

    return ssoProvider.charAt(0).toUpperCase() + ssoProvider.slice(1);
}

function updateRole(user: UserItem, newRole: string) {
    router.visit(UserController.updateRole.url(user.id), {
        method: 'patch',
        data: { role: newRole },
    });
}

function deleteUser(user: UserItem) {
    if (confirm(`Delete ${user.name}? This cannot be undone.`)) {
        router.visit(UserController.destroy.url(user.id), {
            method: 'delete',
        });
    }
}

function unlinkEmby(user: UserItem) {
    if (user.emby_link_id === null) {
        return;
    }

    if (!confirm(`Unlink Emby account "${user.emby_username}" from ${user.name}?`)) {
        return;
    }

    router.visit(UserLinkController.destroy.url(user.emby_link_id), {
        method: 'delete',
        preserveScroll: true,
    });
}

const linkDialogUserId = ref<number | null>(null);
const linkDialogUserName = ref<string>('');
const linkDialogEmbyUsername = ref<string>('');

function openLinkDialog(user: UserItem) {
    linkDialogUserId.value = user.id;
    linkDialogUserName.value = user.name;
    linkDialogEmbyUsername.value = '';
}

function closeLinkDialog() {
    linkDialogUserId.value = null;
    linkDialogUserName.value = '';
    linkDialogEmbyUsername.value = '';
}

const importing = ref(false);

function importFromEmby() {
    if (importing.value) {
        return;
    }

    if (!confirm('Import every Emby user as a viewer account here? Existing accounts and links are skipped.')) {
        return;
    }

    importing.value = true;
    router.post(
        EmbyLinkController.import.url(),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                importing.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Users" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero -->
        <div class="flex items-end justify-between gap-3">
            <div>
                <div class="mb-1.5 text-[13px] text-muted-foreground">
                    Admin <span class="text-fg-subtle">/</span> Users
                </div>
                <h1
                    class="text-[22px] leading-tight font-semibold tracking-tight"
                >
                    Users
                </h1>
                <p class="mt-1 text-[13px] text-muted-foreground">
                    Local accounts, SSO, and Emby-credential users live in one
                    table.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    class="h-7 gap-1.5 text-xs"
                    :disabled="importing"
                    @click="importFromEmby"
                >
                    <Upload class="size-3.5" />Import from Emby
                </Button>
                <Dialog v-model:open="showCreateDialog">
                    <DialogTrigger as-child>
                        <Button size="sm" class="h-7 gap-1.5 text-xs">
                            <Plus class="size-3.5" />Invite user
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{{
                                setPassword ? 'Create user' : 'Invite user'
                            }}</DialogTitle>
                            <DialogDescription>
                                {{
                                    setPassword
                                        ? 'Create a user account with a password.'
                                        : "Send an invitation email. They'll set their own password when they accept."
                                }}
                            </DialogDescription>
                        </DialogHeader>

                        <Form
                            v-bind="UserController.store.post()"
                            v-slot="{ errors, processing }"
                            class="space-y-4"
                            @success="
                                showCreateDialog = false;
                                setPassword = false;
                            "
                        >
                            <input
                                type="hidden"
                                name="set_password"
                                :value="setPassword ? '1' : '0'"
                            />
                            <div class="space-y-2">
                                <Label for="create-name">Name</Label>
                                <Input
                                    id="create-name"
                                    name="name"
                                    required
                                    placeholder="Full name"
                                />
                                <InputError :message="errors.name" />
                            </div>
                            <div class="space-y-2">
                                <Label for="create-email">Email</Label>
                                <Input
                                    id="create-email"
                                    name="email"
                                    type="email"
                                    required
                                    placeholder="email@example.com"
                                />
                                <InputError :message="errors.email" />
                            </div>
                            <div class="space-y-2">
                                <Label for="create-role">Role</Label>
                                <Select name="role" default-value="viewer">
                                    <SelectTrigger>
                                        <SelectValue
                                            placeholder="Select a role"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="role in roles"
                                            :key="role.value"
                                            :value="role.value"
                                        >
                                            {{ role.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError :message="errors.role" />
                            </div>
                            <template v-if="setPassword">
                                <div class="space-y-2">
                                    <Label for="create-password"
                                        >Password</Label
                                    >
                                    <PasswordInput
                                        id="create-password"
                                        name="password"
                                        required
                                        placeholder="Password"
                                    />
                                    <InputError :message="errors.password" />
                                </div>
                                <div class="space-y-2">
                                    <Label for="create-password-confirmation"
                                        >Confirm Password</Label
                                    >
                                    <PasswordInput
                                        id="create-password-confirmation"
                                        name="password_confirmation"
                                        required
                                        placeholder="Confirm password"
                                    />
                                </div>
                            </template>
                            <button
                                type="button"
                                class="text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground"
                                @click="setPassword = !setPassword"
                            >
                                {{
                                    setPassword
                                        ? 'Send invite instead'
                                        : 'Set password manually'
                                }}
                            </button>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    @click="
                                        showCreateDialog = false;
                                        setPassword = false;
                                    "
                                    >Cancel</Button
                                >
                                <Button type="submit" :disabled="processing">
                                    {{
                                        setPassword
                                            ? 'Create user'
                                            : 'Send invitation'
                                    }}
                                </Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>

        <!-- Users table -->
        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            v-for="h in [
                                'User',
                                'Role',
                                'Auth',
                                'Emby link',
                                'Joined',
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
                        v-for="user in users"
                        :key="user.id"
                        class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                    >
                        <td class="px-3 py-2.5">
                            <span class="flex items-center gap-2.5">
                                <InitialsAvatar :name="user.name" :size="24" />
                                <span>
                                    <div class="font-medium">
                                        {{ user.name }}
                                    </div>
                                    <div
                                        class="text-[11.5px] text-muted-foreground"
                                    >
                                        {{ user.email }}
                                    </div>
                                </span>
                            </span>
                        </td>
                        <td class="px-3 py-2.5">
                            <Select
                                v-if="user.id !== currentUserId"
                                :default-value="roleValue(user.role)"
                                @update:model-value="
                                    (val) =>
                                        typeof val === 'string' &&
                                        updateRole(user, val)
                                "
                            >
                                <SelectTrigger class="h-7 w-28 text-xs">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="role in roles"
                                        :key="role.value"
                                        :value="role.value"
                                    >
                                        {{ role.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <Pill
                                v-else
                                :variant="rolePillVariant(user.role)"
                                >{{ roleLabel(user.role) }}</Pill
                            >
                        </td>
                        <td
                            class="font-mono-tabular px-3 py-2.5 text-[11.5px] text-muted-foreground"
                        >
                            {{ authMethod(user.sso_provider) }}
                        </td>
                        <td class="px-3 py-2.5">
                            <span
                                v-if="user.emby_username"
                                class="inline-flex items-center gap-1.5"
                            >
                                <SvcChip id="emby" />
                                <span class="font-mono-tabular text-[11.5px]">
                                    {{ user.emby_username }}
                                </span>
                                <button
                                    type="button"
                                    class="text-[11px] text-muted-foreground underline-offset-2 hover:text-destructive hover:underline"
                                    @click="unlinkEmby(user)"
                                >
                                    unlink
                                </button>
                            </span>
                            <button
                                v-else
                                type="button"
                                class="text-[11.5px] text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                                @click="openLinkDialog(user)"
                            >
                                Link Emby
                            </button>
                        </td>
                        <td
                            class="font-mono-tabular px-3 py-2.5 text-[11.5px] text-fg-subtle"
                        >
                            {{ user.created_at }}
                        </td>
                        <td class="px-3 py-2.5 text-right">
                            <Button
                                v-if="user.id !== currentUserId"
                                variant="ghost"
                                size="sm"
                                class="h-7 px-2 text-xs text-destructive hover:text-destructive"
                                @click="deleteUser(user)"
                            >
                                Delete
                            </Button>
                            <span v-else class="text-[11.5px] text-fg-subtle"
                                >You</span
                            >
                        </td>
                    </tr>
                    <tr v-if="users.length === 0">
                        <td
                            colspan="6"
                            class="px-3 py-8 text-center text-sm text-fg-subtle"
                        >
                            No users yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Dialog
            :open="linkDialogUserId !== null"
            @update:open="(value) => !value && closeLinkDialog()"
        >
            <DialogContent>
                <Form
                    v-if="linkDialogUserId"
                    :action="EmbyLinkController.link.url(linkDialogUserId)"
                    method="post"
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="space-y-4"
                    @success="closeLinkDialog"
                >
                    <DialogHeader>
                        <DialogTitle>
                            Link {{ linkDialogUserName }} to an Emby account
                        </DialogTitle>
                        <DialogDescription>
                            Type the Emby username. The account is found via
                            the configured Emby connection — no password
                            needed.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="space-y-2">
                        <Label for="link-emby-username">Emby username</Label>
                        <Input
                            id="link-emby-username"
                            v-model="linkDialogEmbyUsername"
                            name="emby_username"
                            required
                            placeholder="e.g. rachel"
                        />
                        <InputError :message="errors.emby_username" />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="closeLinkDialog"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="processing">
                            Link account
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
