<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3'
import InputError from '@/components/InputError.vue'
import PasswordInput from '@/components/PasswordInput.vue'
import TextLink from '@/components/TextLink.vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Separator } from '@/components/ui/separator'
import { Spinner } from '@/components/ui/spinner'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible'
import { register } from '@/routes'
import { store } from '@/routes/login'
import { request } from '@/routes/password'
import { ref } from 'vue'

defineOptions({
    layout: {
        title: 'Log in to your account',
        description: 'Choose your preferred sign-in method',
    },
})

defineProps<{
    status?: string
    canResetPassword: boolean
    canRegister: boolean
    authentikEnabled: boolean
    embyEnabled: boolean
}>()

const showEmbyForm = ref(false)
const showLocalForm = ref(false)
</script>

<template>
    <Head title="Log in" />

    <div v-if="status" class="mb-4 text-center text-sm font-medium text-green-600">
        {{ status }}
    </div>

    <div class="flex flex-col gap-4">
        <!-- Authentik SSO -->
        <a v-if="authentikEnabled" :href="route('auth.authentik')" class="w-full">
            <Button variant="default" class="w-full" size="lg">
                Sign in with Authentik
            </Button>
        </a>

        <!-- Emby Login -->
        <div v-if="embyEnabled">
            <Button
                v-if="!showEmbyForm"
                variant="outline"
                class="w-full"
                size="lg"
                @click="showEmbyForm = true"
            >
                Sign in with Emby
            </Button>

            <div v-if="showEmbyForm" class="space-y-4 rounded-lg border p-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-medium">Sign in with Emby</h3>
                    <Button variant="ghost" size="sm" @click="showEmbyForm = false">Cancel</Button>
                </div>

                <Form
                    method="post"
                    :url="route('auth.emby')"
                    v-slot="{ errors, processing }"
                    class="space-y-4"
                >
                    <div class="grid gap-2">
                        <Label for="emby-username">Emby Username</Label>
                        <Input id="emby-username" name="username" required placeholder="Username" />
                        <InputError :message="errors.username" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="emby-password">Emby Password</Label>
                        <PasswordInput id="emby-password" name="password" required placeholder="Password" />
                        <InputError :message="errors.password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="emby-email">Email <span class="text-muted-foreground">(required for first login)</span></Label>
                        <Input id="emby-email" name="email" type="email" placeholder="email@example.com" />
                        <InputError :message="errors.email" />
                    </div>

                    <Button type="submit" class="w-full" :disabled="processing">
                        <Spinner v-if="processing" />
                        Sign in with Emby
                    </Button>
                </Form>
            </div>
        </div>

        <!-- Divider -->
        <div v-if="authentikEnabled || embyEnabled" class="relative my-2">
            <Separator />
            <span class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 bg-background px-2 text-xs text-muted-foreground">
                or sign in with email
            </span>
        </div>

        <!-- Local Login (collapsible if other methods available) -->
        <template v-if="authentikEnabled || embyEnabled">
            <Collapsible v-model:open="showLocalForm">
                <CollapsibleTrigger as-child>
                    <Button variant="ghost" class="w-full text-muted-foreground" size="sm">
                        {{ showLocalForm ? 'Hide email login' : 'Sign in with email' }}
                    </Button>
                </CollapsibleTrigger>
                <CollapsibleContent class="mt-4">
                    <Form
                        v-bind="store.form()"
                        :reset-on-success="['password']"
                        v-slot="{ errors, processing }"
                        class="flex flex-col gap-6"
                    >
                        <div class="grid gap-6">
                            <div class="grid gap-2">
                                <Label for="email">Email address</Label>
                                <Input id="email" type="email" name="email" required autofocus autocomplete="email" placeholder="email@example.com" />
                                <InputError :message="errors.email" />
                            </div>
                            <div class="grid gap-2">
                                <div class="flex items-center justify-between">
                                    <Label for="password">Password</Label>
                                    <TextLink v-if="canResetPassword" :href="request()" class="text-sm">Forgot password?</TextLink>
                                </div>
                                <PasswordInput id="password" name="password" required autocomplete="current-password" placeholder="Password" />
                                <InputError :message="errors.password" />
                            </div>
                            <div class="flex items-center justify-between">
                                <Label for="remember" class="flex items-center space-x-3">
                                    <Checkbox id="remember" name="remember" />
                                    <span>Remember me</span>
                                </Label>
                            </div>
                            <Button type="submit" class="w-full" :disabled="processing" data-test="login-button">
                                <Spinner v-if="processing" />
                                Log in
                            </Button>
                        </div>
                    </Form>
                </CollapsibleContent>
            </Collapsible>
        </template>

        <!-- Local Login (full form when no other methods) -->
        <template v-else>
            <Form
                v-bind="store.form()"
                :reset-on-success="['password']"
                v-slot="{ errors, processing }"
                class="flex flex-col gap-6"
            >
                <div class="grid gap-6">
                    <div class="grid gap-2">
                        <Label for="email">Email address</Label>
                        <Input id="email" type="email" name="email" required autofocus autocomplete="email" placeholder="email@example.com" />
                        <InputError :message="errors.email" />
                    </div>
                    <div class="grid gap-2">
                        <div class="flex items-center justify-between">
                            <Label for="password">Password</Label>
                            <TextLink v-if="canResetPassword" :href="request()" class="text-sm">Forgot password?</TextLink>
                        </div>
                        <PasswordInput id="password" name="password" required autocomplete="current-password" placeholder="Password" />
                        <InputError :message="errors.password" />
                    </div>
                    <div class="flex items-center justify-between">
                        <Label for="remember" class="flex items-center space-x-3">
                            <Checkbox id="remember" name="remember" />
                            <span>Remember me</span>
                        </Label>
                    </div>
                    <Button type="submit" class="w-full" :disabled="processing" data-test="login-button">
                        <Spinner v-if="processing" />
                        Log in
                    </Button>
                </div>
            </Form>
        </template>

        <div class="text-center text-sm text-muted-foreground" v-if="canRegister">
            Don't have an account?
            <TextLink :href="register()">Sign up</TextLink>
        </div>
    </div>
</template>
