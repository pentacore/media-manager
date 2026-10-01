<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { Link2, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import UserLinkController from '@/actions/App/Http/Controllers/Emby/UserLinkController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useCan } from '@/composables/useCan';
import { dashboard } from '@/routes';
import type { EmbyUserLinkResource } from '@/typefinder/resources/EmbyUserLinkResource';

type UserLink = EmbyUserLinkResource;

interface AppUser {
    id: number;
    name: string;
    email: string;
}

interface EmbyDirectoryUser {
    id: string;
    name: string;
    is_admin: boolean;
    last_activity_at: string | null;
    link: { id: number; user: { id: number; name: string } } | null;
}

const props = defineProps<{
    links?: UserLink[];
    appUsers?: AppUser[];
    embyUsers?: { users: EmbyDirectoryUser[]; error: string | null };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Emby Links', href: UserLinkController.index.url() },
        ],
    },
});

const page = usePage();
const { can } = useCan();

const isAdmin = computed(() => can('admin'));

const currentUserId = computed(() => page.props.auth.user?.id ?? null);

const myLink = computed<UserLink | null>(() => {
    if (!props.links || currentUserId.value === null) {
        return null;
    }

    return (
        props.links.find((link) => link.user?.id === currentUserId.value) ??
        null
    );
});

function formatDate(iso: string | null): string {
    if (!iso) {
        return '-';
    }

    return new Date(iso).toLocaleDateString();
}

function revoke(link: UserLink) {
    router.delete(UserLinkController.destroy.url(link.id), {
        preserveScroll: true,
    });
}

const linkSelection = ref<Record<string, string>>({});
const linking = ref<string | null>(null);

function linkEmbyUser(embyUser: EmbyDirectoryUser): void {
    const userId = Number(linkSelection.value[embyUser.id]);

    if (!userId || linking.value !== null) {
        return;
    }

    linking.value = embyUser.id;
    router.post(
        UserLinkController.storeFromDirectory.url(),
        { user_id: userId, emby_user_id: embyUser.id },
        {
            preserveScroll: true,
            onFinish: () => {
                linking.value = null;
            },
        },
    );
}
</script>

<template>
    <Head title="Emby Links" />

    <div class="space-y-6 p-6">
        <div>
            <h2
                class="flex items-center gap-2 text-2xl font-bold tracking-tight"
            >
                <Link2 class="size-6" />
                Emby Links
            </h2>
            <p class="text-muted-foreground">
                Connect your application account to an Emby user to track
                playback activity.
            </p>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Your Emby Account</CardTitle>
                <CardDescription>
                    {{
                        myLink
                            ? 'Your account is linked.'
                            : 'Link your Emby account to your profile.'
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div
                    v-if="myLink"
                    class="flex items-center justify-between gap-4"
                >
                    <div>
                        <p class="font-medium">{{ myLink.emby_username }}</p>
                        <p class="text-xs text-muted-foreground">
                            Linked {{ formatDate(myLink.created_at) }}
                        </p>
                    </div>
                    <Button
                        variant="destructive"
                        size="sm"
                        @click="revoke(myLink)"
                    >
                        <Trash2 class="mr-2 size-4" />
                        Unlink
                    </Button>
                </div>

                <Form
                    v-else
                    v-bind="UserLinkController.store.form()"
                    v-slot="{ errors, processing }"
                    class="space-y-4"
                >
                    <div class="space-y-2">
                        <Label for="emby_username">Emby Username</Label>
                        <Input
                            id="emby_username"
                            name="emby_username"
                            required
                            placeholder="Your Emby username"
                        />
                        <InputError :message="errors.emby_username" />
                    </div>
                    <div class="space-y-2">
                        <Label for="emby_password">Emby Password</Label>
                        <PasswordInput
                            id="emby_password"
                            name="password"
                            required
                            placeholder="Your Emby password"
                        />
                        <InputError :message="errors.password" />
                    </div>
                    <Button type="submit" :disabled="processing">
                        <Link2 class="mr-2 size-4" />
                        Link Account
                    </Button>
                </Form>
            </CardContent>
        </Card>

        <Card v-if="isAdmin">
            <CardHeader>
                <CardTitle>All Linked Accounts</CardTitle>
                <CardDescription>
                    {{ props.links?.length ?? 0 }} linked
                    {{
                        (props.links?.length ?? 0) === 1
                            ? 'account'
                            : 'accounts'
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Emby Username</TableHead>
                            <TableHead>App User</TableHead>
                            <TableHead>Linked</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="link in props.links ?? []"
                            :key="link.id"
                        >
                            <TableCell class="font-medium">{{
                                link.emby_username
                            }}</TableCell>
                            <TableCell>
                                <span v-if="link.user">
                                    {{ link.user.name }}
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{ link.user.email }}</span
                                    >
                                </span>
                                <Badge v-else variant="outline">Unlinked</Badge>
                            </TableCell>
                            <TableCell class="text-muted-foreground">{{
                                formatDate(link.created_at)
                            }}</TableCell>
                            <TableCell class="text-right">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="revoke(link)"
                                >
                                    <Trash2 class="mr-2 size-4" />
                                    Revoke
                                </Button>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="(props.links?.length ?? 0) === 0">
                            <TableCell
                                :colspan="4"
                                class="py-8 text-center text-muted-foreground"
                            >
                                No linked accounts yet.
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <Card v-if="isAdmin" data-emby-users>
            <CardHeader>
                <CardTitle>Emby users</CardTitle>
                <CardDescription>
                    Everyone on the Emby server. Link an account to an app user
                    without needing their Emby password.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div v-if="props.embyUsers === undefined" class="space-y-2">
                    <Skeleton v-for="n in 3" :key="n" class="h-10 w-full" />
                </div>
                <div
                    v-else-if="props.embyUsers.error"
                    class="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                    data-emby-users-error
                >
                    {{ props.embyUsers.error }}
                </div>
                <Table v-else>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Emby user</TableHead>
                            <TableHead>Last activity</TableHead>
                            <TableHead>App user</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="embyUser in props.embyUsers.users"
                            :key="embyUser.id"
                            :data-emby-user="embyUser.id"
                        >
                            <TableCell class="font-medium">
                                {{ embyUser.name }}
                                <Badge
                                    v-if="embyUser.is_admin"
                                    variant="outline"
                                    class="ml-2"
                                    >Emby admin</Badge
                                >
                            </TableCell>
                            <TableCell class="text-muted-foreground">{{
                                formatDate(embyUser.last_activity_at)
                            }}</TableCell>
                            <TableCell>
                                <span v-if="embyUser.link">{{
                                    embyUser.link.user.name
                                }}</span>
                                <div v-else class="flex items-center gap-2">
                                    <Select
                                        :model-value="
                                            linkSelection[embyUser.id]
                                        "
                                        @update:model-value="
                                            (value) =>
                                                (linkSelection[embyUser.id] =
                                                    String(value))
                                        "
                                    >
                                        <SelectTrigger
                                            class="h-8 w-48 text-xs"
                                            :data-emby-link-trigger="
                                                embyUser.id
                                            "
                                        >
                                            <SelectValue
                                                placeholder="Choose an app user"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                v-for="appUser in props.appUsers ??
                                                []"
                                                :key="appUser.id"
                                                :value="String(appUser.id)"
                                                :data-emby-link-option="
                                                    appUser.id
                                                "
                                            >
                                                {{ appUser.name }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        :disabled="
                                            !linkSelection[embyUser.id] ||
                                            linking !== null
                                        "
                                        :data-emby-link-submit="embyUser.id"
                                        @click="linkEmbyUser(embyUser)"
                                    >
                                        <Link2 class="mr-1 size-4" />Link
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="props.embyUsers.users.length === 0">
                            <TableCell
                                :colspan="3"
                                class="py-8 text-center text-muted-foreground"
                            >
                                Emby reports no users.
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    </div>
</template>
