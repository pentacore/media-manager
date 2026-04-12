<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3'
import ServiceConnectionController from '@/actions/App/Http/Controllers/Admin/ServiceConnectionController'
import InputError from '@/components/InputError.vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select'

interface ServiceTypeOption {
    value: string
    label: string
}

defineProps<{
    serviceTypes: ServiceTypeOption[]
}>()

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: '#' },
            { title: 'Connections', href: ServiceConnectionController.index.url() },
            { title: 'Add Connection', href: ServiceConnectionController.create.url() },
        ],
    },
})
</script>

<template>
    <Head title="Add Connection" />

    <div class="max-w-2xl p-6">
        <Card>
            <CardHeader>
                <CardTitle>Add Service Connection</CardTitle>
                <CardDescription>Connect an external service to MediaManager.</CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="ServiceConnectionController.store.post()"
                    class="space-y-4"
                    v-slot="{ errors, processing }"
                >
                    <div class="space-y-2">
                        <Label for="type">Service Type</Label>
                        <Select name="type">
                            <SelectTrigger>
                                <SelectValue placeholder="Select a service type" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="serviceType in serviceTypes"
                                    :key="serviceType.value"
                                    :value="serviceType.value"
                                >
                                    {{ serviceType.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="errors.type" />
                    </div>

                    <div class="space-y-2">
                        <Label for="name">Display Name</Label>
                        <Input id="name" name="name" placeholder="My Sonarr" />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="space-y-2">
                        <Label for="url">URL</Label>
                        <Input id="url" name="url" placeholder="http://sonarr.local:8989" />
                        <InputError :message="errors.url" />
                    </div>

                    <div class="space-y-2">
                        <Label for="api_key">API Key</Label>
                        <Input id="api_key" name="api_key" type="password" placeholder="Enter API key" />
                        <InputError :message="errors.api_key" />
                    </div>

                    <div class="space-y-2">
                        <Label for="webhook_token">Webhook Token</Label>
                        <Input id="webhook_token" name="webhook_token" type="password" placeholder="Token for webhook authentication" />
                        <p class="text-sm text-muted-foreground">
                            Configure this token in the service's webhook settings as the X-Webhook-Token header.
                        </p>
                        <InputError :message="errors.webhook_token" />
                    </div>

                    <div class="flex gap-2 pt-4">
                        <Button type="submit" :disabled="processing">Create Connection</Button>
                        <Link :href="ServiceConnectionController.index.url()">
                            <Button type="button" variant="outline">Cancel</Button>
                        </Link>
                    </div>
                </Form>
            </CardContent>
        </Card>
    </div>
</template>
