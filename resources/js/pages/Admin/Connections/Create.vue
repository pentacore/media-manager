<script setup lang="ts">
import { Form, Head, Link, useHttp } from '@inertiajs/vue3';
import { ClipboardCopy, Eye, EyeOff, Plug, RefreshCw } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import ServiceConnectionController from '@/actions/App/Http/Controllers/Admin/ServiceConnectionController';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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

defineProps<{
    serviceTypes: ServiceTypeOption[];
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

const selectedType = ref('');
const webhookToken = ref('');
const serviceUrl = ref('');
const apiKey = ref('');

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
} as Record<string, { name: string; url: string; apiKey: string }>;

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
                    class="space-y-4"
                    v-slot="{ errors, processing }"
                >
                    <div class="space-y-2">
                        <Label for="type">Service Type</Label>
                        <Select name="type" v-model="selectedType">
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
                                :type="tokenVisible ? 'text' : 'password'"
                                placeholder="Token for webhook authentication"
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
                            settings as the X-Webhook-Token header.
                        </p>
                        <InputError :message="errors.webhook_token" />
                    </div>

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
