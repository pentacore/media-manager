<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import InputError from '@/components/InputError.vue';
import { Field } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

type Props = {
    mustVerifyEmail: boolean;
    status?: string;
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

        <DeleteUser />
    </div>
</template>
