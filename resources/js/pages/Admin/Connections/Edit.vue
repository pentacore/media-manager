<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ServiceConnectionController from '@/actions/App/Http/Controllers/Admin/ServiceConnectionController';
import {
    BazarrConnectionMappings,
    ConnectionTestButton,
    DiskDisplayPicker,
    NOT_CONNECTED_VALUE,
    ProwlarrIndexersCard,
    SabnzbdHiddenCategories,
    SabnzbdNotificationScript,
    ServiceTypeSelect,
    SonarrLibraryTypes,
    SubtitleCheckTags,
    WebhookTokenField,
    WebhookUrlField,
    WhisparrVersionSelect,
    withBazarrMappingIds,
} from '@/components/connections';
import type {
    ArrConnectionOption,
    ArrTag,
    DiskPath,
    EditableConnection,
    ProwlarrIndexer,
    ServiceTypeOption,
    SonarrRootFolder,
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

const props = defineProps<{
    connection: EditableConnection;
    serviceTypes: ServiceTypeOption[];
    arrConnections: ArrConnectionOption[];
    indexers?: ProwlarrIndexer[];
    availableDiskPaths?: DiskPath[];
    sonarrRootFolders?: SonarrRootFolder[];
    arrTags?: ArrTag[] | null;
    subtitleCheckTags?: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: '#' },
            {
                title: 'Connections',
                href: ServiceConnectionController.index.url(),
            },
            { title: 'Edit', href: '#' },
        ],
    },
});

const typeValue =
    typeof props.connection.type === 'string'
        ? props.connection.type
        : props.connection.type.value;

// Page-level state: the Test Connection button reads the type, URL and key,
// and the Bazarr mapping ids must survive the mapping selects unmounting
// when the service type changes.
const selectedType = ref(typeValue);
const selectedSonarrConnectionId = ref(
    props.connection.sonarr_connection_id === null
        ? NOT_CONNECTED_VALUE
        : String(props.connection.sonarr_connection_id),
);
const selectedRadarrConnectionId = ref(
    props.connection.radarr_connection_id === null
        ? NOT_CONNECTED_VALUE
        : String(props.connection.radarr_connection_id),
);
const serviceUrl = ref(props.connection.url);
const apiKey = ref('');
const whisparrVersion = ref(props.connection.whisparr_version ?? 'v3');

const showWhisparrVersion = computed(() => typeValue === 'whisparr');
const showBazarrMappings = computed(() => selectedType.value === 'bazarr');
const supportsDiskPicker = computed(
    () => typeValue === 'sonarr' || typeValue === 'radarr',
);
const supportsHiddenCategories = computed(() => typeValue === 'sabnzbd');
const supportsSonarrLibraryTypes = computed(() => typeValue === 'sonarr');
const supportsSubtitleCheckTags = computed(
    () => typeValue === 'sonarr' || typeValue === 'radarr',
);
</script>

<template>
    <Head title="Edit Connection" />

    <div class="max-w-2xl p-6">
        <Card>
            <CardHeader>
                <CardTitle>Edit Connection</CardTitle>
                <CardDescription
                    >Update the settings for this service
                    connection.</CardDescription
                >
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="
                        ServiceConnectionController.update.form(connection.id)
                    "
                    :transform="withBazarrMappingIds"
                    class="space-y-4"
                    v-slot="{ errors, processing }"
                >
                    <ServiceTypeSelect
                        v-model="selectedType"
                        :service-types="serviceTypes"
                        :default-value="typeValue"
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
                        :default-value="whisparrVersion"
                    />

                    <div class="space-y-2">
                        <Label for="name">Display Name</Label>
                        <Input
                            id="name"
                            name="name"
                            :default-value="connection.name"
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="space-y-2">
                        <Label for="url">URL</Label>
                        <Input
                            id="url"
                            v-model="serviceUrl"
                            name="url"
                            :default-value="connection.url"
                        />
                        <InputError :message="errors.url" />
                    </div>

                    <div class="space-y-2">
                        <Label for="external_url">External URL</Label>
                        <Input
                            id="external_url"
                            name="external_url"
                            :default-value="connection.external_url ?? ''"
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
                            autocomplete="new-password"
                            :placeholder="
                                connection.api_key_set
                                    ? '•••••••• (set — leave blank to keep)'
                                    : 'Enter API key'
                            "
                        />
                        <p class="text-sm text-muted-foreground">
                            Leave blank to keep the existing value.
                        </p>
                        <InputError :message="errors.api_key" />
                    </div>

                    <ConnectionTestButton
                        :type="selectedType"
                        :url="serviceUrl"
                        :api-key="apiKey"
                    >
                        <p
                            v-if="connection.api_key_set && !apiKey"
                            class="text-sm text-muted-foreground"
                        >
                            Enter the API key above to test the connection.
                        </p>
                    </ConnectionTestButton>

                    <WebhookTokenField
                        :placeholder="
                            connection.webhook_token_set
                                ? '•••••••• (set — leave blank to keep)'
                                : 'Enter webhook token'
                        "
                        autocomplete="new-password"
                        :error="errors.webhook_token"
                    >
                        <p class="text-sm text-muted-foreground">
                            Configure this token in the service's webhook
                            settings as the X-Webhook-Token header — or use the
                            URL below as-is, which appends ?token= for services
                            (Sonarr/Radarr/Prowlarr) that don't support custom
                            headers. Leave blank to keep the existing value.
                        </p>
                    </WebhookTokenField>

                    <WebhookUrlField
                        :connection-id="connection.id"
                        :webhook-url="connection.webhook_url"
                        :supports-configuration="
                            connection.supports_webhook_configuration
                        "
                    />

                    <DiskDisplayPicker
                        v-if="supportsDiskPicker"
                        :disk="connection.disk"
                        :available-disk-paths="availableDiskPaths"
                    />

                    <SonarrLibraryTypes
                        v-if="supportsSonarrLibraryTypes"
                        :root-folders="sonarrRootFolders"
                        :errors="errors"
                    />

                    <SubtitleCheckTags
                        v-if="supportsSubtitleCheckTags"
                        :arr-tags="arrTags"
                        :selected-tags="subtitleCheckTags"
                    />

                    <SabnzbdHiddenCategories
                        v-if="supportsHiddenCategories"
                        :initial-categories="connection.hidden_categories ?? []"
                    />

                    <SabnzbdNotificationScript
                        v-if="connection.sabnzbd_webhook_script"
                        :script="connection.sabnzbd_webhook_script"
                    />

                    <div class="flex gap-2 pt-4">
                        <Button type="submit" :disabled="processing"
                            >Update Connection</Button
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

        <ProwlarrIndexersCard
            v-if="typeValue === 'prowlarr'"
            :connection-id="connection.id"
            :indexers="indexers"
        />
    </div>
</template>
