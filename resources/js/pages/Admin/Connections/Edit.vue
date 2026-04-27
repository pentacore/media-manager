<script setup lang="ts">
import { Form, Head, Link, router, useHttp } from '@inertiajs/vue3';
import { Antenna, ClipboardCopy, Eye, EyeOff, Plug, RefreshCw } from 'lucide-vue-next';
import { reactive, ref } from 'vue';
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

interface ServiceTypeOption {
    value: string;
    label: string;
}

interface Connection {
    id: number;
    type: { value: string } | string;
    name: string;
    url: string;
    api_key_set: boolean;
    webhook_token_set: boolean;
    is_active: boolean;
}

interface Indexer {
    id: number;
    name: string;
    enable: boolean;
    priority: number;
    implementation?: string;
}

const props = defineProps<{
    connection: Connection;
    serviceTypes: ServiceTypeOption[];
    indexers: Indexer[];
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
const serviceUrl = ref(props.connection.url);
const apiKey = ref('');
const webhookToken = ref('');
const copied = ref(false);
const tokenVisible = ref(false);

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

function copyWebhookToken() {
    if (!webhookToken.value) {
        return;
    }

    navigator.clipboard.writeText(webhookToken.value);
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
                        ServiceConnectionController.update.put(connection.id)
                    "
                    class="space-y-4"
                    v-slot="{ errors, processing }"
                >
                    <div class="space-y-2">
                        <Label for="type">Service Type</Label>
                        <Select
                            name="type"
                            v-model="selectedType"
                            :default-value="typeValue"
                        >
                            <SelectTrigger>
                                <SelectValue
                                    placeholder="Select a service type"
                                />
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
                            settings as the X-Webhook-Token header. Leave blank
                            to keep the existing value.
                        </p>
                        <InputError :message="errors.webhook_token" />
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
                    v-if="indexers.length === 0"
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
                        <TableRow
                            v-for="indexer in indexers"
                            :key="indexer.id"
                        >
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
