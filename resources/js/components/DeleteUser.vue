<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useTemplateRef } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';

const passwordInput = useTemplateRef('passwordInput');
</script>

<template>
    <div class="rounded-xl border border-destructive/30 bg-destructive/5 p-6">
        <div class="mb-4">
            <h2
                class="text-[16px] leading-tight font-semibold tracking-tight text-destructive"
            >
                Delete account
            </h2>
            <p class="mt-1 text-[13px] text-muted-foreground">
                Permanently remove your account and every resource tied to it.
                This cannot be undone.
            </p>
        </div>

        <Dialog>
            <DialogTrigger as-child>
                <Button
                    variant="destructive"
                    size="sm"
                    class="h-8 text-xs"
                    data-test="delete-user-button"
                >
                    Delete account
                </Button>
            </DialogTrigger>
            <DialogContent>
                <Form
                    v-bind="ProfileController.destroy.form()"
                    reset-on-success
                    @error="() => passwordInput?.focus()"
                    :options="{
                        preserveScroll: true,
                    }"
                    class="space-y-6"
                    v-slot="{ errors, processing, reset, clearErrors }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle> Delete this account? </DialogTitle>
                        <DialogDescription>
                            Once your account is deleted, all of its resources
                            and data will also be permanently deleted. Enter
                            your password to confirm.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="password" class="sr-only">Password</Label>
                        <PasswordInput
                            id="password"
                            name="password"
                            ref="passwordInput"
                            placeholder="Password"
                        />
                        <InputError :message="errors.password" />
                    </div>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button
                                variant="secondary"
                                @click="
                                    () => {
                                        clearErrors();
                                        reset();
                                    }
                                "
                            >
                                Cancel
                            </Button>
                        </DialogClose>

                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="processing"
                            data-test="confirm-delete-user-button"
                        >
                            Delete account
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
