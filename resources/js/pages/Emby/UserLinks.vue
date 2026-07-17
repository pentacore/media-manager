<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { Link2, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import UserLinkController from '@/actions/App/Http/Controllers/Emby/UserLinkController';
import { dashboard } from '@/routes';
import type { EmbyUserLinkResource } from '@/typefinder/resources/EmbyUserLinkResource';

type UserLink = EmbyUserLinkResource;

const props = defineProps<{ links?: UserLink[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Emby Links', href: UserLinkController.index.url() },
        ],
    },
});

const page = usePage();

const isAdmin = computed(() => {
    const role = page.props.auth.user?.role;

    if (!role) {
        return false;
    }

    const value = typeof role === 'string' ? role : role.value;

    return value === 'admin';
});

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
    </div>
</template>
