<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ShieldCheck } from 'lucide-vue-next';
import { onUnmounted, ref } from 'vue';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/InputError.vue';
import { Field, Pill } from '@/components/mm';
import PasswordInput from '@/components/PasswordInput.vue';
import TwoFactorRecoveryCodes from '@/components/TwoFactorRecoveryCodes.vue';
import TwoFactorSetupModal from '@/components/TwoFactorSetupModal.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { edit } from '@/routes/security';
import { disable, enable } from '@/routes/two-factor';

type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

withDefaults(defineProps<Props>(), {
    canManageTwoFactor: false,
    requiresConfirmation: false,
    twoFactorEnabled: false,
});

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Security settings',
                href: edit(),
            },
        ],
    },
});

const { hasSetupData, clearTwoFactorAuthData } = useTwoFactorAuth();
const showSetupModal = ref<boolean>(false);

onUnmounted(() => clearTwoFactorAuthData());
</script>

<template>
    <Head title="Security settings" />

    <h1 class="sr-only">Security settings</h1>

    <div class="flex flex-col gap-6">
        <div>
            <div class="mb-1.5 text-[13px] text-muted-foreground">
                Settings <span class="text-fg-subtle">/</span> Security
            </div>
            <h2 class="text-[18px] leading-tight font-semibold tracking-tight">
                Update password
            </h2>
            <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                Use a long, random password to keep this account secure.
            </p>
        </div>

        <Form
            v-bind="SecurityController.update.form()"
            :options="{
                preserveScroll: true,
            }"
            reset-on-success
            :reset-on-error="[
                'password',
                'password_confirmation',
                'current_password',
            ]"
            v-slot="{ errors, processing }"
            class="rounded-xl border border-border bg-card p-6"
        >
            <div class="flex flex-col gap-5">
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Current password"
                        hint="Confirms it's really you."
                    >
                        <span />
                    </Field>
                    <div>
                        <PasswordInput
                            id="current_password"
                            name="current_password"
                            class="h-8 max-w-[360px] text-sm"
                            autocomplete="current-password"
                            placeholder="Current password"
                        />
                        <InputError
                            :message="errors.current_password"
                            class="mt-1"
                        />
                    </div>
                </div>

                <Separator />

                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field label="New password" hint="At least 8 characters.">
                        <span />
                    </Field>
                    <div>
                        <PasswordInput
                            id="password"
                            name="password"
                            class="h-8 max-w-[360px] text-sm"
                            autocomplete="new-password"
                            placeholder="New password"
                        />
                        <InputError :message="errors.password" class="mt-1" />
                    </div>
                </div>

                <Separator />

                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Confirm password"
                        hint="Type the new password again."
                    >
                        <span />
                    </Field>
                    <div>
                        <PasswordInput
                            id="password_confirmation"
                            name="password_confirmation"
                            class="h-8 max-w-[360px] text-sm"
                            autocomplete="new-password"
                            placeholder="Confirm password"
                        />
                        <InputError
                            :message="errors.password_confirmation"
                            class="mt-1"
                        />
                    </div>
                </div>

                <div class="flex justify-end pt-2">
                    <Button
                        size="sm"
                        type="submit"
                        :disabled="processing"
                        class="h-8 text-xs"
                        data-test="update-password-button"
                    >
                        Save password
                    </Button>
                </div>
            </div>
        </Form>

        <template v-if="canManageTwoFactor">
            <div>
                <h2
                    class="text-[18px] leading-tight font-semibold tracking-tight"
                >
                    Two-factor authentication
                </h2>
                <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                    Require a one-time code from a TOTP app at sign-in.
                </p>
            </div>

            <div class="rounded-xl border border-border bg-card p-6">
                <div class="mb-4 flex items-center gap-2">
                    <span class="text-[13px] font-medium">Status</span>
                    <Pill
                        :variant="twoFactorEnabled ? 'ok' : 'default'"
                        :dot="twoFactorEnabled"
                    >
                        {{ twoFactorEnabled ? 'Enabled' : 'Disabled' }}
                    </Pill>
                </div>

                <div v-if="!twoFactorEnabled" class="space-y-4">
                    <p class="text-[13px] text-muted-foreground">
                        After enabling, you'll be prompted for a secure pin
                        during login. Retrieve it from a TOTP-supported app on
                        your phone.
                    </p>
                    <div>
                        <Button
                            v-if="hasSetupData"
                            size="sm"
                            class="h-8 text-xs"
                            @click="showSetupModal = true"
                        >
                            <ShieldCheck class="size-3.5" />
                            Continue setup
                        </Button>
                        <Form
                            v-else
                            v-bind="enable.form()"
                            @success="showSetupModal = true"
                            #default="{ processing }"
                        >
                            <Button
                                type="submit"
                                size="sm"
                                class="h-8 text-xs"
                                :disabled="processing"
                            >
                                Enable 2FA
                            </Button>
                        </Form>
                    </div>
                </div>

                <div v-else class="space-y-4">
                    <p class="text-[13px] text-muted-foreground">
                        Sign-in requires a TOTP pin from your authenticator app.
                    </p>
                    <Form v-bind="disable.form()" #default="{ processing }">
                        <Button
                            variant="destructive"
                            type="submit"
                            size="sm"
                            class="h-8 text-xs"
                            :disabled="processing"
                        >
                            Disable 2FA
                        </Button>
                    </Form>

                    <TwoFactorRecoveryCodes />
                </div>
            </div>

            <TwoFactorSetupModal
                v-model:isOpen="showSetupModal"
                :requiresConfirmation="requiresConfirmation"
                :twoFactorEnabled="twoFactorEnabled"
            />
        </template>
    </div>
</template>
