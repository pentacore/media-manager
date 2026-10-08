<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ServiceConnectionController from '@/actions/App/Http/Controllers/Admin/ServiceConnectionController';
import {
    BazarrConnectionMappings,
    ConnectionTestButton,
    NOT_CONNECTED_VALUE,
    ServiceTypeSelect,
    WebhookTokenField,
    WhisparrVersionSelect,
    withBazarrMappingIds,
} from '@/components/connections';
import type {
    ArrConnectionOption,
    ServiceTypeOption,
} from '@/components/connections';
import InputError from '@/components/InputError.vue';
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

defineProps<{
    serviceTypes: ServiceTypeOption[];
    arrConnections: ArrConnectionOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: '#' },
            {
                title: 'Connections',
                href: ServiceConnectionController.index.url(),
            },
            {
                title: 'Add Connection',
                href: ServiceConnectionController.create.url(),
            },
        ],
    },
});

// Page-level state: the Test Connection button reads the type, URL and key,
// and the Bazarr mapping ids and Whisparr version must survive their selects
// unmounting when the service type changes.
const selectedType = ref('');
const selectedSonarrConnectionId = ref(NOT_CONNECTED_VALUE);
const selectedRadarrConnectionId = ref(NOT_CONNECTED_VALUE);
const serviceUrl = ref('');
const apiKey = ref('');

const servicePlaceholders = {
    sonarr: {
        name: 'My Sonarr',
        url: 'http://sonarr.local:8989',
        apiKey: 'Sonarr API key (Settings → General)',
    },
    radarr: {
        name: 'My Radarr',
        url: 'http://radarr.local:7878',
        apiKey: 'Radarr API key (Settings → General)',
    },
    emby: {
        name: 'My Emby',
        url: 'http://emby.local:8096',
        apiKey: 'Emby API key (Dashboard → API Keys)',
    },
    seerr: {
        name: 'My Seerr',
        url: 'http://seerr.local:5055',
        apiKey: 'Seerr API key (Settings → General)',
    },
    whisparr: {
        name: 'My Whisparr',
        url: 'http://whisparr.local:6969',
        apiKey: 'Whisparr API key (Settings → General)',
    },
} as Record<string, { name: string; url: string; apiKey: string }>;

const whisparrVersion = ref('v3');
const showWhisparrVersion = computed(() => selectedType.value === 'whisparr');
const showBazarrMappings = computed(() => selectedType.value === 'bazarr');

const placeholders = computed(
    () =>
        servicePlaceholders[selectedType.value] ?? {
            name: 'Display name',
            url: 'http://service.local:port',
            apiKey: 'Enter API key',
        },
);
</script>

<template>
    <Head title="Add Connection" />

    <div class="max-w-2xl p-6">
        <Card>
            <CardHeader>
                <CardTitle>Add Service Connection</CardTitle>
                <CardDescription
                    >Connect an external service to
                    MediaManager.</CardDescription
                >
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="ServiceConnectionController.store.form.post()"
                    :transform="withBazarrMappingIds"
                    class="space-y-4"
                    v-slot="{ errors, processing }"
                >
                    <ServiceTypeSelect
                        v-model="selectedType"
                        :service-types="serviceTypes"
                        :error="errors.type"
                    />

                    <BazarrConnectionMappings
                        v-if="showBazarrMappings"
                        v-model:sonarr-connection-id="
                            selectedSonarrConnectionId
                        "
                        v-model:radarr-connection-id="
                            selectedRadarrConnectionId
                        "
                        :arr-connections="arrConnections"
                        :errors="errors"
                    />

                    <WhisparrVersionSelect
                        v-if="showWhisparrVersion"
                        v-model="whisparrVersion"
                    />

                    <div class="space-y-2">
                        <Label for="name">Display Name</Label>
                        <Input
                            id="name"
                            name="name"
                            :placeholder="placeholders.name"
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="space-y-2">
                        <Label for="url">URL</Label>
                        <Input
                            id="url"
                            v-model="serviceUrl"
                            name="url"
                            :placeholder="placeholders.url"
                        />
                        <InputError :message="errors.url" />
                    </div>

                    <div class="space-y-2">
                        <Label for="external_url">External URL</Label>
                        <Input
                            id="external_url"
                            name="external_url"
                            placeholder="https://service.example.com (optional)"
                        />
                        <p class="text-sm text-muted-foreground">
                            Used for user-facing links; falls back to URL.
                        </p>
                        <InputError :message="errors.external_url" />
                    </div>

                    <div class="space-y-2">
                        <Label for="api_key">API Key</Label>
                        <Input
                            id="api_key"
                            v-model="apiKey"
                            name="api_key"
                            type="password"
                            :placeholder="placeholders.apiKey"
                        />
                        <InputError :message="errors.api_key" />
                    </div>

                    <ConnectionTestButton
                        :type="selectedType"
                        :url="serviceUrl"
                        :api-key="apiKey"
                    />

                    <WebhookTokenField
                        placeholder="Token for webhook authentication"
                        :error="errors.webhook_token"
                    >
                        <p class="text-sm text-muted-foreground">
                            Configure this token in the service's webhook
                            settings as the X-Webhook-Token header.
                        </p>
                    </WebhookTokenField>

                    <div class="flex gap-2 pt-4">
                        <Button type="submit" :disabled="processing"
                            >Create Connection</Button
                        >
                        <Link :href="ServiceConnectionController.index.url()">
                            <Button type="button" variant="outline"
                                >Cancel</Button
                            >
                        </Link>
                    </div>
                </Form>
            </CardContent>
        </Card>
    </div>
</template>
