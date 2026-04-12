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

interface Connection {
    id: number
    type: { value: string } | string
    name: string
    url: string
    api_key: string
    webhook_token: string
    is_active: boolean
}

const props = defineProps<{
    connection: Connection
    serviceTypes: ServiceTypeOption[]
}>()

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: '#' },
            { title: 'Connections', href: ServiceConnectionController.index.url() },
            { title: 'Edit', href: '#' },
        ],
    },
})

const typeValue = typeof props.connection.type === 'string'
    ? props.connection.type
    : props.connection.type.value
</script>

<template>
    <Head title="Edit Connection" />

    <div class="max-w-2xl p-6">
        <Card>
            <CardHeader>
                <CardTitle>Edit Connection</CardTitle>
                <CardDescription>Update the settings for this service connection.</CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="ServiceConnectionController.update.put(connection.id)"
                    class="space-y-4"
                    v-slot="{ errors, processing }"
                >
                    <div class="space-y-2">
                        <Label for="type">Service Type</Label>
                        <Select name="type" :default-value="typeValue">
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
                        <Input id="name" name="name" :default-value="connection.name" />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="space-y-2">
                        <Label for="url">URL</Label>
                        <Input id="url" name="url" :default-value="connection.url" />
                        <InputError :message="errors.url" />
                    </div>

                    <div class="space-y-2">
                        <Label for="api_key">API Key</Label>
                        <Input id="api_key" name="api_key" type="password" :default-value="connection.api_key" />
                        <InputError :message="errors.api_key" />
                    </div>

                    <div class="space-y-2">
                        <Label for="webhook_token">Webhook Token</Label>
                        <Input id="webhook_token" name="webhook_token" type="password" :default-value="connection.webhook_token" />
                        <p class="text-sm text-muted-foreground">
                            Configure this token in the service's webhook settings as the X-Webhook-Token header.
                        </p>
                        <InputError :message="errors.webhook_token" />
                    </div>

                    <div class="flex gap-2 pt-4">
                        <Button type="submit" :disabled="processing">Update Connection</Button>
                        <Link :href="ServiceConnectionController.index.url()">
                            <Button type="button" variant="outline">Cancel</Button>
                        </Link>
                    </div>
                </Form>
            </CardContent>
        </Card>
    </div>
</template>
