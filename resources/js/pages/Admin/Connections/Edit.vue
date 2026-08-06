<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head, Link, router, useHttp } from '@inertiajs/vue3';
import {
    Antenna,
    ClipboardCopy,
    Eye,
    EyeOff,
    Plug,
    RefreshCw,
    Wand2,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import ProwlarrTestIndexerController from '@/actions/App/Http/Controllers/Admin/ProwlarrTestIndexerController';
import ServiceConnectionController from '@/actions/App/Http/Controllers/Admin/ServiceConnectionController';
import InputError from '@/components/InputError.vue';
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { copyToClipboard } from '@/lib/clipboard';

interface ServiceTypeOption {
    value: string;
    label: string;
}

interface ArrConnectionOption {
    id: number;
    type: 'sonarr' | 'radarr';
    name: string;
}

interface Connection {
    id: number;
    type: { value: string } | string;
    name: string;
    url: string;
    external_url: string | null;
    api_key_set: boolean;
    webhook_token_set: boolean;
    webhook_url: string;
    supports_webhook_configuration: boolean;
    is_active: boolean;
    disk: {
        mode: 'all' | 'selected' | 'sum';
        paths: string[];
        display: Record<string, 'free' | 'used' | 'both'>;
    };
    hidden_categories?: string[];
    sabnzbd_webhook_script?: string | null;
    whisparr_version?: string;
    sonarr_connection_id: number | null;
    radarr_connection_id: number | null;
}

interface Indexer {
    id: number;
    name: string;
    enable: boolean;
    priority: number;
    implementation?: string;
}

interface DiskPath {
    path: string;
    label: string | null;
}

interface SonarrRootFolder {
    root_folder_id: number;
    path: string;
    scope: 'anime' | 'tv' | null;
}

interface ArrTag {
    id: number;
    label: string;
}

const props = defineProps<{
    connection: Connection;
    serviceTypes: ServiceTypeOption[];
    arrConnections: ArrConnectionOption[];
    indexers?: Indexer[];
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

const selectedType = ref(typeValue);
const notConnectedValue = 'not-connected';
const selectedSonarrConnectionId = ref(
    props.connection.sonarr_connection_id === null
        ? notConnectedValue
        : String(props.connection.sonarr_connection_id),
);
const selectedRadarrConnectionId = ref(
    props.connection.radarr_connection_id === null
        ? notConnectedValue
        : String(props.connection.radarr_connection_id),
);
const serviceUrl = ref(props.connection.url);
const apiKey = ref('');
const webhookToken = ref('');
const copied = ref(false);
const tokenVisible = ref(false);

type DiskMetric = 'free' | 'used' | 'both';

const diskMode = ref<'all' | 'selected' | 'sum'>(
    props.connection.disk?.mode ?? 'all',
);
const selectedDiskPaths = ref<string[]>([
    ...(props.connection.disk?.paths ?? []),
]);
const diskDisplay = reactive<Record<string, DiskMetric>>({
    ...(props.connection.disk?.display ?? {}),
});

const whisparrVersion = ref(props.connection.whisparr_version ?? 'v3');
const showWhisparrVersion = computed(() => typeValue === 'whisparr');
const showBazarrMappings = computed(() => selectedType.value === 'bazarr');
const sonarrConnections = computed(() =>
    props.arrConnections.filter((connection) => connection.type === 'sonarr'),
);
const radarrConnections = computed(() =>
    props.arrConnections.filter((connection) => connection.type === 'radarr'),
);

const supportsDiskPicker = computed(
    () => typeValue === 'sonarr' || typeValue === 'radarr',
);

const supportsHiddenCategories = computed(() => typeValue === 'sabnzbd');
const supportsSonarrLibraryTypes = computed(() => typeValue === 'sonarr');
const supportsSubtitleCheckTags = computed(
    () => typeValue === 'sonarr' || typeValue === 'radarr',
);

// Backend stores `hidden_categories` as a string[] under settings; the
// form edits a single comma-separated text field for simplicity.
const hiddenCategoriesText = ref<string>(
    (props.connection.hidden_categories ?? []).join(', '),
);
const hiddenCategoriesList = computed<string[]>(() =>
    hiddenCategoriesText.value
        .split(',')
        .map((s) => s.trim())
        .filter((s) => s.length > 0),
);

function toggleDiskPath(path: string): void {
    const idx = selectedDiskPaths.value.indexOf(path);

    if (idx === -1) {
        selectedDiskPaths.value.push(path);

        if (!diskDisplay[path]) {
            diskDisplay[path] = 'both';
        }
    } else {
        selectedDiskPaths.value.splice(idx, 1);
    }
}

function diskDisplayFor(key: string): DiskMetric {
    return diskDisplay[key] ?? 'both';
}

function setDiskDisplay(key: string, value: DiskMetric): void {
    diskDisplay[key] = value;
}

const diskDisplayEntries = computed<Array<[string, DiskMetric]>>(() => {
    const entries: Array<[string, DiskMetric]> = [];

    for (const path of selectedDiskPaths.value) {
        entries.push([path, diskDisplayFor(path)]);
    }

    if (diskMode.value === 'sum') {
        entries.push(['sum', diskDisplayFor('sum')]);
    }

    return entries;
});

const configuringWebhook = ref(false);

function configureWebhookOnService(): void {
    configuringWebhook.value = true;

    router.post(
        ServiceConnectionController.configureWebhook(props.connection.id).url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                configuringWebhook.value = false;
            },
        },
    );
}

interface TestConnectionResponse {
    success: boolean;
    message: string;
    version?: string;
}

const testResult = ref<TestConnectionResponse | null>(null);
const testHttp = useHttp<
    { type: string; url: string; api_key: string },
    TestConnectionResponse
>({ type: '', url: '', api_key: '' });

function testConnection() {
    testResult.value = null;
    testHttp.type = selectedType.value;
    testHttp.url = serviceUrl.value;
    testHttp.api_key = apiKey.value;
    testHttp.post(ServiceConnectionController.test.url(), {
        onSuccess: (response) => {
            testResult.value = response;
        },
        onError: () => {
            testResult.value = {
                success: false,
                message: 'Connection failed.',
            };
        },
    });
}

function generateWebhookToken() {
    const bytes = crypto.getRandomValues(new Uint8Array(32));
    webhookToken.value = Array.from(bytes, (b) =>
        b.toString(16).padStart(2, '0'),
    ).join('');
    copied.value = false;
}

const webhookUrlCopied = ref(false);

async function copyWebhookUrl() {
    const ok = await copyToClipboard(props.connection.webhook_url);

    if (!ok) {
        return;
    }

    webhookUrlCopied.value = true;
    setTimeout(() => (webhookUrlCopied.value = false), 2000);
}

const sabScriptCopied = ref(false);

async function copySabScript(): Promise<void> {
    const script = props.connection.sabnzbd_webhook_script;

    if (!script) {
        return;
    }

    const ok = await copyToClipboard(script);

    if (!ok) {
        return;
    }

    sabScriptCopied.value = true;
    setTimeout(() => (sabScriptCopied.value = false), 2000);
}

async function copyWebhookToken() {
    if (!webhookToken.value) {
        return;
    }

    const ok = await copyToClipboard(webhookToken.value);

    if (!ok) {
        return;
    }

    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}

const testing = reactive<Record<number, boolean>>({});

function testIndexer(indexerId: number): void {
    testing[indexerId] = true;

    router.post(
        ProwlarrTestIndexerController([props.connection.id, indexerId]).url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                testing[indexerId] = false;
            },
        },
    );
}
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
                    :transform="
                        (data: Record<string, FormDataConvertible>) => ({
                            ...data,
                            sonarr_connection_id:
                                data.sonarr_connection_id === notConnectedValue
                                    ? null
                                    : data.sonarr_connection_id,
                            radarr_connection_id:
                                data.radarr_connection_id === notConnectedValue
                                    ? null
                                    : data.radarr_connection_id,
                        })
                    "
                    class="space-y-4"
                    v-slot="{ errors, processing }"
                >
                    <div class="space-y-2">
                        <Label for="service_type">Service Type</Label>
                        <Select
                            name="type"
                            v-model="selectedType"
                            :default-value="typeValue"
                        >
                            <SelectTrigger id="service_type" class="w-full">
                                <SelectValue
                                    placeholder="Select a service type"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="serviceType in serviceTypes"
                                    :key="serviceType.value"
                                    :value="serviceType.value"
                                    :aria-label="serviceType.label"
                                >
                                    {{ serviceType.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="errors.type" />
                    </div>

                    <div
                        v-if="showBazarrMappings"
                        class="grid gap-4 sm:grid-cols-2"
                    >
                        <div class="space-y-2">
                            <Label for="sonarr_connection_id"
                                >Sonarr connection</Label
                            >
                            <Select
                                name="sonarr_connection_id"
                                v-model="selectedSonarrConnectionId"
                            >
                                <SelectTrigger
                                    id="sonarr_connection_id"
                                    class="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        :value="notConnectedValue"
                                        aria-label="No Sonarr connection"
                                        >Not connected</SelectItem
                                    >
                                    <SelectItem
                                        v-for="connection in sonarrConnections"
                                        :key="connection.id"
                                        :value="String(connection.id)"
                                        :aria-label="`Use ${connection.name} as Sonarr connection`"
                                    >
                                        {{ connection.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError
                                :message="errors.sonarr_connection_id"
                            />
                        </div>

                        <div class="space-y-2">
                            <Label for="radarr_connection_id"
                                >Radarr connection</Label
                            >
                            <Select
                                name="radarr_connection_id"
                                v-model="selectedRadarrConnectionId"
                            >
                                <SelectTrigger
                                    id="radarr_connection_id"
                                    class="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        :value="notConnectedValue"
                                        aria-label="No Radarr connection"
                                        >Not connected</SelectItem
                                    >
                                    <SelectItem
                                        v-for="connection in radarrConnections"
                                        :key="connection.id"
                                        :value="String(connection.id)"
                                        :aria-label="`Use ${connection.name} as Radarr connection`"
                                    >
                                        {{ connection.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError
                                :message="errors.radarr_connection_id"
                            />
                        </div>
                    </div>

                    <div v-if="showWhisparrVersion" class="space-y-2">
                        <Label for="whisparr_version">Whisparr Version</Label>
                        <Select
                            name="whisparr_version"
                            v-model="whisparrVersion"
                            :default-value="whisparrVersion"
                        >
                            <SelectTrigger>
                                <SelectValue
                                    placeholder="Select Whisparr version"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="v3"
                                    >v3 (movie-based)</SelectItem
                                >
                                <SelectItem value="v2"
                                    >v2 (Eros / series-based)</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>

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

                    <div class="space-y-2">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="
                                !selectedType ||
                                !serviceUrl ||
                                !apiKey ||
                                testHttp.processing
                            "
                            @click="testConnection"
                        >
                            <Plug class="mr-2 size-4" />
                            {{
                                testHttp.processing
                                    ? 'Testing...'
                                    : 'Test Connection'
                            }}
                        </Button>
                        <p
                            v-if="connection.api_key_set && !apiKey"
                            class="text-sm text-muted-foreground"
                        >
                            Enter the API key above to test the connection.
                        </p>
                        <p
                            v-if="testResult?.success"
                            class="text-sm text-green-600 dark:text-green-400"
                        >
                            {{ testResult.message }}
                            <span v-if="testResult.version">
                                (v{{ testResult.version }})</span
                            >
                        </p>
                        <p
                            v-else-if="testResult && !testResult.success"
                            class="text-sm text-destructive"
                        >
                            {{ testResult.message }}
                        </p>
                    </div>

                    <div class="space-y-2">
                        <Label for="webhook_token">Webhook Token</Label>
                        <div class="flex gap-2">
                            <Input
                                id="webhook_token"
                                v-model="webhookToken"
                                name="webhook_token"
                                autocomplete="new-password"
                                :type="tokenVisible ? 'text' : 'password'"
                                :placeholder="
                                    connection.webhook_token_set
                                        ? '•••••••• (set — leave blank to keep)'
                                        : 'Enter webhook token'
                                "
                            />
                            <TooltipProvider :delay-duration="0">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            :disabled="!webhookToken"
                                            @click="
                                                tokenVisible = !tokenVisible
                                            "
                                        >
                                            <EyeOff
                                                v-if="tokenVisible"
                                                class="size-4"
                                            />
                                            <Eye v-else class="size-4" />
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>{{
                                        tokenVisible
                                            ? 'Hide token'
                                            : 'Show token'
                                    }}</TooltipContent>
                                </Tooltip>
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            :disabled="!webhookToken"
                                            @click="copyWebhookToken"
                                        >
                                            <ClipboardCopy class="size-4" />
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>{{
                                        copied ? 'Copied!' : 'Copy to clipboard'
                                    }}</TooltipContent>
                                </Tooltip>
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            @click="generateWebhookToken"
                                        >
                                            <RefreshCw class="size-4" />
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent
                                        >Generate token</TooltipContent
                                    >
                                </Tooltip>
                            </TooltipProvider>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Configure this token in the service's webhook
                            settings as the X-Webhook-Token header — or use the
                            URL below as-is, which appends ?token= for services
                            (Sonarr/Radarr/Prowlarr) that don't support custom
                            headers. Leave blank to keep the existing value.
                        </p>
                        <InputError :message="errors.webhook_token" />
                    </div>

                    <div class="space-y-2">
                        <Label>Webhook URL</Label>
                        <div class="flex gap-2">
                            <Input
                                readonly
                                :default-value="connection.webhook_url"
                                :model-value="connection.webhook_url"
                                class="font-mono-tabular text-xs"
                                @click="
                                    (e: Event) =>
                                        (e.target as HTMLInputElement).select()
                                "
                            />
                            <TooltipProvider :delay-duration="0">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            @click="copyWebhookUrl"
                                        >
                                            <ClipboardCopy class="size-4" />
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>{{
                                        webhookUrlCopied
                                            ? 'Copied!'
                                            : 'Copy webhook URL'
                                    }}</TooltipContent>
                                </Tooltip>
                                <Tooltip
                                    v-if="
                                        connection.supports_webhook_configuration
                                    "
                                >
                                    <TooltipTrigger as-child>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            :disabled="configuringWebhook"
                                            @click="configureWebhookOnService"
                                        >
                                            <Wand2 class="size-4" />
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>{{
                                        configuringWebhook
                                            ? 'Configuring…'
                                            : 'Configure on service'
                                    }}</TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Paste this into the upstream service's webhook
                            configuration — or click the wand button to push it
                            automatically (Sonarr/Radarr/Prowlarr only). The
                            token in the URL is in addition to the
                            X-Webhook-Token header — either is accepted.
                        </p>
                    </div>

                    <div v-if="supportsDiskPicker" class="space-y-3 pt-2">
                        <div>
                            <Label>Service Health · disk display</Label>
                            <p class="text-sm text-muted-foreground">
                                Choose which root paths show up on the Service
                                Health page. "Sum" collapses the selected paths
                                into a single total row.
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <label
                                v-for="opt in [
                                    ['all', 'Show all'],
                                    ['selected', 'Show selected'],
                                    ['sum', 'Sum selected'],
                                ] as const"
                                :key="opt[0]"
                                class="inline-flex cursor-pointer items-center gap-2 rounded-md border border-border px-3 py-1.5 text-sm transition-colors"
                                :class="
                                    diskMode === opt[0]
                                        ? 'border-accent/50 bg-accent/10 text-accent'
                                        : 'hover:bg-bg-hover'
                                "
                            >
                                <input
                                    type="radio"
                                    :value="opt[0]"
                                    v-model="diskMode"
                                    class="sr-only"
                                />
                                {{ opt[1] }}
                            </label>
                        </div>

                        <div
                            v-if="diskMode !== 'all'"
                            class="rounded-md border border-border bg-bg-elev p-3"
                        >
                            <div
                                v-if="!availableDiskPaths"
                                class="text-sm text-muted-foreground"
                            >
                                Loading disk paths from the service…
                            </div>
                            <div
                                v-else-if="availableDiskPaths.length === 0"
                                class="text-sm text-fg-subtle"
                            >
                                No disk paths reported. Save with the URL + API
                                key first, then revisit to pick paths.
                            </div>
                            <div v-else class="flex flex-col gap-2">
                                <div
                                    v-for="entry in availableDiskPaths"
                                    :key="entry.path"
                                    class="flex flex-wrap items-center gap-3 text-sm"
                                >
                                    <label
                                        class="flex cursor-pointer items-center gap-2"
                                    >
                                        <input
                                            type="checkbox"
                                            :value="entry.path"
                                            :checked="
                                                selectedDiskPaths.includes(
                                                    entry.path,
                                                )
                                            "
                                            class="size-4 rounded border-border"
                                            @change="toggleDiskPath(entry.path)"
                                        />
                                        <span class="font-mono-tabular">{{
                                            entry.path
                                        }}</span>
                                        <span
                                            v-if="entry.label"
                                            class="text-xs text-muted-foreground"
                                            >({{ entry.label }})</span
                                        >
                                    </label>
                                    <div
                                        v-if="
                                            diskMode === 'selected' &&
                                            selectedDiskPaths.includes(
                                                entry.path,
                                            )
                                        "
                                        class="ml-auto flex items-center gap-1 rounded-md border border-border bg-card p-0.5 text-xs"
                                    >
                                        <button
                                            v-for="metric in [
                                                'free',
                                                'used',
                                                'both',
                                            ] as const"
                                            :key="metric"
                                            type="button"
                                            class="inline-flex h-6 items-center rounded px-2 transition-colors"
                                            :class="
                                                diskDisplayFor(entry.path) ===
                                                metric
                                                    ? 'bg-accent text-accent-foreground'
                                                    : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground'
                                            "
                                            @click="
                                                setDiskDisplay(
                                                    entry.path,
                                                    metric,
                                                )
                                            "
                                        >
                                            {{ metric }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div
                                v-if="diskMode === 'sum'"
                                class="mt-3 flex flex-wrap items-center gap-3 border-t border-border pt-3 text-sm"
                            >
                                <span class="font-medium">Sum row display</span>
                                <div
                                    class="ml-auto flex items-center gap-1 rounded-md border border-border bg-card p-0.5 text-xs"
                                >
                                    <button
                                        v-for="metric in [
                                            'free',
                                            'used',
                                            'both',
                                        ] as const"
                                        :key="metric"
                                        type="button"
                                        class="inline-flex h-6 items-center rounded px-2 transition-colors"
                                        :class="
                                            diskDisplayFor('sum') === metric
                                                ? 'bg-accent text-accent-foreground'
                                                : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground'
                                        "
                                        @click="setDiskDisplay('sum', metric)"
                                    >
                                        {{ metric }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <input
                            type="hidden"
                            name="disk_mode"
                            :value="diskMode"
                        />
                        <input
                            v-for="path in selectedDiskPaths"
                            :key="path"
                            type="hidden"
                            name="disk_paths[]"
                            :value="path"
                        />
                        <input
                            v-for="entry in diskDisplayEntries"
                            :key="`display-${entry[0]}`"
                            type="hidden"
                            :name="`disk_display[${entry[0]}]`"
                            :value="entry[1]"
                        />
                    </div>

                    <div
                        v-if="supportsSonarrLibraryTypes"
                        class="space-y-3 pt-2"
                    >
                        <div>
                            <Label>Sonarr library types</Label>
                            <p class="text-sm text-muted-foreground">
                                Classify this connection's root folders by
                                content. Sonarr's series type describes episode
                                numbering and does not reliably identify anime.
                            </p>
                        </div>

                        <div
                            v-if="!sonarrRootFolders"
                            class="rounded-md border border-border bg-bg-elev px-3 py-2 text-sm text-muted-foreground"
                        >
                            Loading root folders from Sonarr…
                        </div>
                        <div
                            v-else-if="sonarrRootFolders.length === 0"
                            class="rounded-md border border-dashed border-border px-3 py-2 text-sm text-muted-foreground"
                        >
                            No root folders could be imported. Save a working
                            URL and API key, then revisit this connection.
                        </div>
                        <div v-else class="space-y-2">
                            <div
                                v-for="(rootFolder, index) in sonarrRootFolders"
                                :key="rootFolder.root_folder_id"
                                class="grid gap-3 rounded-md border border-border px-3 py-2.5 sm:grid-cols-[minmax(0,1fr)_180px] sm:items-start"
                            >
                                <div
                                    class="min-w-0 self-center truncate font-mono text-xs text-muted-foreground"
                                    :title="rootFolder.path"
                                >
                                    {{ rootFolder.path }}
                                </div>

                                <div class="space-y-1">
                                    <input
                                        type="hidden"
                                        :name="`sonarr_root_folders[${index}][root_folder_id]`"
                                        :value="rootFolder.root_folder_id"
                                    />
                                    <input
                                        type="hidden"
                                        :name="`sonarr_root_folders[${index}][path]`"
                                        :value="rootFolder.path"
                                    />
                                    <select
                                        :id="`sonarr_root_scope_${rootFolder.root_folder_id}`"
                                        :name="`sonarr_root_folders[${index}][scope]`"
                                        :value="rootFolder.scope ?? ''"
                                        class="h-8 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                    >
                                        <option value="">Unassigned</option>
                                        <option value="anime">Anime</option>
                                        <option value="tv">TV</option>
                                    </select>
                                    <InputError
                                        :message="
                                            errors[
                                                `sonarr_root_folders.${index}.scope`
                                            ]
                                        "
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="supportsSubtitleCheckTags"
                        class="space-y-3 pt-2"
                    >
                        <div>
                            <Label>Automatic subtitle check</Label>
                            <p class="text-sm text-muted-foreground">
                                Completed downloads for series or movies
                                carrying one of these tags are checked for the
                                required subtitle languages, and a replacement
                                is proposed when a language is missing.
                            </p>
                        </div>

                        <div
                            v-if="arrTags === undefined"
                            class="rounded-md border border-border bg-bg-elev px-3 py-2 text-sm text-muted-foreground"
                        >
                            Loading tags…
                        </div>
                        <div
                            v-else-if="arrTags === null"
                            class="rounded-md border border-border bg-bg-elev px-3 py-2 text-sm text-muted-foreground"
                            data-testid="subtitle-check-tags-unavailable"
                        >
                            Tags could not be loaded. Check that this connection
                            is enabled and that its URL and API key are correct,
                            then revisit this page.
                        </div>
                        <div
                            v-else-if="arrTags.length === 0"
                            class="rounded-md border border-dashed border-border px-3 py-2 text-sm text-muted-foreground"
                        >
                            No tags are defined on this instance yet.
                        </div>
                        <div v-else class="space-y-2">
                            <!--
                                Keeps the field present when every box is
                                unticked: an unticked checkbox group submits
                                nothing, and an absent field means "keep the
                                stored tags", so without this the selection
                                could never be cleared. The backend receives
                                it as null and drops it, leaving an empty
                                list.

                                Deliberately inside this branch only — while
                                tags are loading or unavailable the form must
                                not claim an empty selection and wipe the
                                stored one.
                            -->
                            <input
                                type="hidden"
                                name="subtitle_check_tags[]"
                                value=""
                            />
                            <label
                                v-for="tag in arrTags"
                                :key="tag.id"
                                class="flex items-center gap-2 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    name="subtitle_check_tags[]"
                                    :value="tag.label"
                                    :checked="
                                        (subtitleCheckTags ?? []).includes(
                                            tag.label.toLowerCase(),
                                        )
                                    "
                                    class="size-4 rounded border-input"
                                />
                                <span class="font-mono text-xs">
                                    {{ tag.label }}
                                </span>
                            </label>
                        </div>
                    </div>

                    <div v-if="supportsHiddenCategories" class="space-y-3 pt-2">
                        <div>
                            <Label for="hidden_categories">
                                Hidden categories
                            </Label>
                            <p class="text-sm text-muted-foreground">
                                Comma-separated SABnzbd categories whose queue
                                and history rows should be hidden everywhere in
                                the app. Leave empty to show everything.
                            </p>
                        </div>
                        <Input
                            id="hidden_categories"
                            v-model="hiddenCategoriesText"
                            placeholder="e.g. adult, private"
                            autocomplete="off"
                        />
                        <input
                            v-for="category in hiddenCategoriesList"
                            :key="category"
                            type="hidden"
                            name="hidden_categories[]"
                            :value="category"
                        />
                    </div>

                    <div
                        v-if="connection.sabnzbd_webhook_script"
                        class="space-y-3 pt-2"
                    >
                        <div class="flex items-end justify-between gap-2">
                            <div>
                                <Label>Notification script</Label>
                                <p class="text-sm text-muted-foreground">
                                    SABnzbd doesn't have native HTTP webhooks,
                                    but it can run a notification script per
                                    event. Save this Python file into your SAB
                                    <code>scripts/</code> folder, then pick it
                                    under Settings → Notifications. Token is
                                    embedded in the URL.
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="h-7 gap-1.5 text-xs"
                                @click="copySabScript"
                            >
                                <ClipboardCopy class="size-3.5" />
                                {{ sabScriptCopied ? 'Copied!' : 'Copy' }}
                            </Button>
                        </div>
                        <pre
                            class="max-h-72 overflow-auto rounded-md border border-border bg-bg-elev p-3 text-[11.5px] leading-snug"
                            >{{ connection.sabnzbd_webhook_script }}</pre>
                    </div>

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

        <Card v-if="typeValue === 'prowlarr'" class="mt-6">
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <Antenna class="size-5" />
                    Configured Indexers
                </CardTitle>
                <CardDescription>
                    Read-only — manage indexer config in Prowlarr's own UI.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <p
                    v-if="!indexers"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Antenna class="size-4" />
                    Loading indexers…
                </p>
                <p
                    v-else-if="indexers.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No indexers loaded. Either Prowlarr isn't reachable right
                    now, or none are configured yet.
                </p>
                <Table v-else>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Implementation</TableHead>
                            <TableHead class="text-right">Priority</TableHead>
                            <TableHead class="text-center">Enabled</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="indexer in indexers" :key="indexer.id">
                            <TableCell class="font-medium">{{
                                indexer.name
                            }}</TableCell>
                            <TableCell class="text-muted-foreground">{{
                                indexer.implementation ?? '-'
                            }}</TableCell>
                            <TableCell class="text-right">{{
                                indexer.priority
                            }}</TableCell>
                            <TableCell class="text-center">
                                <Badge
                                    :variant="
                                        indexer.enable ? 'default' : 'outline'
                                    "
                                >
                                    {{
                                        indexer.enable ? 'Enabled' : 'Disabled'
                                    }}
                                </Badge>
                            </TableCell>
                            <TableCell class="text-right">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    :disabled="testing[indexer.id]"
                                    @click="testIndexer(indexer.id)"
                                >
                                    {{
                                        testing[indexer.id]
                                            ? 'Testing...'
                                            : 'Test'
                                    }}
                                </Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    </div>
</template>
