<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import { Link2 } from '@lucide/vue';
import { computed } from 'vue';
import DeleteUser from '@/components/DeleteUser.vue';
import InputError from '@/components/InputError.vue';
import { Field, Pill, SvcChip } from '@/components/mm';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import UserLinkController from '@/actions/App/Http/Controllers/Emby/UserLinkController';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

interface EmbyLink {
    id: number;
    emby_username: string;
    created_at: string | null;
}

type Props = {
    mustVerifyEmail: boolean;
    status?: string;
    embyLinks: EmbyLink[];
};

defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

function unlinkEmby(link: EmbyLink) {
    if (!confirm(`Unlink Emby account "${link.emby_username}"?`)) {
        return;
    }

    router.delete(UserLinkController.destroy.url(link.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col gap-6">
        <div>
            <div class="mb-1.5 text-[13px] text-muted-foreground">
                Settings <span class="text-fg-subtle">/</span> Profile
            </div>
            <h2 class="text-[18px] leading-tight font-semibold tracking-tight">
                Profile information
            </h2>
            <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                Update your name and email address.
            </p>
        </div>

        <Form
            v-bind="ProfileController.update.form()"
            v-slot="{ errors, processing }"
            class="rounded-xl border border-border bg-card p-6"
        >
            <div class="flex flex-col gap-5">
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Name"
                        hint="Shown across the app and in audit logs."
                    >
                        <span />
                    </Field>
                    <div>
                        <Input
                            id="name"
                            class="h-8 max-w-[360px] text-sm"
                            name="name"
                            :default-value="user.name"
                            required
                            autocomplete="name"
                            placeholder="Full name"
                        />
                        <InputError :message="errors.name" class="mt-1" />
                    </div>
                </div>

                <Separator />

                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Email"
                        hint="Used for sign-in and account notifications."
                    >
                        <span />
                    </Field>
                    <div>
                        <Input
                            id="email"
                            type="email"
                            class="h-8 max-w-[360px] text-sm"
                            name="email"
                            :default-value="user.email"
                            required
                            autocomplete="username"
                            placeholder="Email address"
                        />
                        <InputError :message="errors.email" class="mt-1" />

                        <div
                            v-if="mustVerifyEmail && !user.email_verified_at"
                            class="mt-2 text-[12px] text-muted-foreground"
                        >
                            Email address is unverified.
                            <Link
                                :href="send()"
                                as="button"
                                class="text-foreground underline decoration-border underline-offset-4 transition-colors hover:decoration-current"
                            >
                                Resend verification email.
                            </Link>
                        </div>

                        <div
                            v-if="status === 'verification-link-sent'"
                            class="mt-2 text-[12px] font-medium text-svc-emby"
                        >
                            New verification link sent.
                        </div>
                    </div>
                </div>

                <div class="flex justify-end pt-2">
                    <Button
                        size="sm"
                        type="submit"
                        :disabled="processing"
                        class="h-8 text-xs"
                        data-test="update-profile-button"
                    >
                        Save
                    </Button>
                </div>
            </div>
        </Form>

        <div>
            <h2 class="text-[18px] leading-tight font-semibold tracking-tight">
                Emby account
            </h2>
            <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                Link your Emby login so playback events show up in your watch
                history.
            </p>
        </div>

        <div class="space-y-4 rounded-xl border border-border bg-card p-6">
            <div
                v-for="link in embyLinks"
                :key="link.id"
                class="flex items-center justify-between gap-4"
            >
                <div class="flex items-center gap-2.5">
                    <SvcChip id="emby" />
                    <div>
                        <div class="text-[14px] font-semibold">
                            {{ link.emby_username }}
                        </div>
                        <div
                            class="font-mono-tabular text-[11.5px] text-muted-foreground"
                        >
                            Linked
                            {{
                                link.created_at
                                    ? new Date(
                                          link.created_at,
                                      ).toLocaleDateString()
                                    : '—'
                            }}
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Pill variant="ok" dot>connected</Pill>
                    <Button
                        variant="destructive"
                        size="sm"
                        class="h-8 text-xs"
                        @click="unlinkEmby(link)"
                    >
                        Unlink
                    </Button>
                </div>
            </div>

            <Separator v-if="embyLinks.length" />

            <Form
                v-bind="UserLinkController.store.form()"
                v-slot="{ errors, processing }"
                class="space-y-4"
            >
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Emby username"
                        hint="The display name you use to sign in to Emby."
                    >
                        <span />
                    </Field>
                    <div>
                        <Input
                            id="emby-username"
                            class="h-8 max-w-[360px] text-sm"
                            name="emby_username"
                            required
                            placeholder="rachel"
                        />
                        <InputError
                            :message="errors.emby_username"
                            class="mt-1"
                        />
                    </div>
                </div>
                <Separator />
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Emby password"
                        hint="Verifies it's actually you. Stored only as a session check, not retained."
                    >
                        <span />
                    </Field>
                    <div>
                        <PasswordInput
                            id="emby-password"
                            name="password"
                            class="h-8 max-w-[360px] text-sm"
                            required
                            placeholder="Emby password"
                        />
                        <InputError :message="errors.password" class="mt-1" />
                    </div>
                </div>
                <div class="flex justify-end">
                    <Button
                        size="sm"
                        type="submit"
                        :disabled="processing"
                        class="h-8 text-xs"
                    >
                        <Link2 class="size-3.5" />
                        Link Emby account
                    </Button>
                </div>
            </Form>
        </div>

        <DeleteUser />
    </div>
</template>
