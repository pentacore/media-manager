<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    edit,
    test as testRoute,
    update,
} from '@/routes/settings/notifications';

type SeverityFlags = Record<string, boolean>;

interface CatalogEntry {
    class: string;
    label: string;
    description: string;
    severities: Record<string, SeverityFlags>;
}

interface Destinations {
    ntfy_topic: string | null;
    discord_webhook_url_hint: string | null;
    telegram_chat_id: string | null;
    webhook_url: string | null;
    webhook_secret_set: boolean;
}

const props = defineProps<{
    catalog: CatalogEntry[];
    channels: string[];
    severities: string[];
    destinations: Destinations;
    channelsConfigured: { ntfy: boolean; telegram: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notification preferences', href: edit() }],
    },
});

const working = reactive<CatalogEntry[]>(
    props.catalog.map((entry) => ({
        ...entry,
        severities: Object.fromEntries(
            Object.entries(entry.severities).map(([sev, flags]) => [
                sev,
                { ...flags },
            ]),
        ),
    })),
);

const SEVERITY_LABELS: Record<string, string> = {
    info: 'Info',
    warning: 'Warning',
    error: 'Error',
};

const CHANNEL_LABELS: Record<string, string> = {
    database: 'In-app',
    broadcast: 'Toast / live',
    mail: 'Email',
    ntfy: 'ntfy',
    discord: 'Discord',
    telegram: 'Telegram',
    webhook: 'Webhook',
};

function channelLabel(channel: string): string {
    return CHANNEL_LABELS[channel] ?? channel;
}

const saving = ref(false);
const testingChannel = ref<string | null>(null);

// Non-secret fields are always sent; '' clears.
const ntfyTopic = ref(props.destinations.ntfy_topic ?? '');
const telegramChatId = ref(props.destinations.telegram_chat_id ?? '');
const webhookUrl = ref(props.destinations.webhook_url ?? '');

// Secret fields: the input starts blank and is only sent when touched.
const discordWebhookUrl = ref('');
const discordTouched = ref(false);
const webhookSecret = ref('');
const webhookSecretTouched = ref(false);

const hasDiscord = computed(
    () => props.destinations.discord_webhook_url_hint !== null,
);
const hasWebhook = computed(() => props.destinations.webhook_url !== null);
const hasTelegram = computed(
    () => props.destinations.telegram_chat_id !== null,
);
const hasNtfy = computed(() => props.destinations.ntfy_topic !== null);

// A test send always targets the saved destination, so block it while the
// field on screen differs from what the server last stored.
const ntfyUnsaved = computed(
    () => ntfyTopic.value !== (props.destinations.ntfy_topic ?? ''),
);
const telegramUnsaved = computed(
    () => telegramChatId.value !== (props.destinations.telegram_chat_id ?? ''),
);
const webhookUnsaved = computed(
    () => webhookUrl.value !== (props.destinations.webhook_url ?? ''),
);

function clearSecret(field: 'discord' | 'webhookSecret'): void {
    if (field === 'discord') {
        discordWebhookUrl.value = '';
        discordTouched.value = true;
    } else {
        webhookSecret.value = '';
        webhookSecretTouched.value = true;
    }
}

function sendTest(channel: string): void {
    testingChannel.value = channel;
    router.post(
        testRoute().url,
        { channel },
        {
            preserveScroll: true,
            onFinish: () => {
                testingChannel.value = null;
            },
        },
    );
}

function save(): void {
    saving.value = true;
    const payload: Record<string, FormDataConvertible> = {
        preferences: working.map((entry) => ({
            class: entry.class,
            severities: Object.fromEntries(
                Object.entries(entry.severities).map(([sev, flags]) => [
                    sev,
                    { ...flags },
                ]),
            ),
        })),
        ntfy_topic: ntfyTopic.value === '' ? null : ntfyTopic.value,
        telegram_chat_id:
            telegramChatId.value === '' ? null : telegramChatId.value,
        webhook_url: webhookUrl.value === '' ? null : webhookUrl.value,
    };

    if (discordTouched.value) {
        payload.discord_webhook_url = discordWebhookUrl.value;
    }

    if (webhookSecretTouched.value) {
        payload.webhook_secret = webhookSecret.value;
    }

    router.put(update().url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            discordTouched.value = false;
            webhookSecretTouched.value = false;
            discordWebhookUrl.value = '';
            webhookSecret.value = '';
        },
        onFinish: () => {
            saving.value = false;
        },
    });
}
</script>

<template>
    <Head title="Notification preferences" />

    <h1 class="sr-only">Notification preferences</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Notification preferences"
            description="Pick which channels each notification reaches you on. Defaults: in-app + live toast on."
        />

        <div class="space-y-6">
            <div
                v-for="entry in working"
                :key="entry.class"
                class="rounded-xl border border-border bg-card"
            >
                <div class="border-b border-border px-4 py-3">
                    <div class="text-[14px] font-semibold">
                        {{ entry.label }}
                    </div>
                    <p class="mt-1 text-[12.5px] text-muted-foreground">
                        {{ entry.description }}
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-[12.5px]">
                        <thead>
                            <tr>
                                <th
                                    class="border-b border-border bg-card px-3 py-2 text-left text-[11px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                                >
                                    Severity
                                </th>
                                <th
                                    v-for="channel in channels"
                                    :key="channel"
                                    class="border-b border-border bg-card px-3 py-2 text-center text-[11px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                                >
                                    {{ channelLabel(channel) }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="severity in severities"
                                :key="severity"
                                class="border-b border-border last:border-b-0"
                            >
                                <td class="px-3 py-2.5 font-medium">
                                    {{ SEVERITY_LABELS[severity] ?? severity }}
                                </td>
                                <td
                                    v-for="channel in channels"
                                    :key="channel"
                                    class="px-3 py-2.5 text-center"
                                >
                                    <input
                                        type="checkbox"
                                        v-model="
                                            entry.severities[severity][channel]
                                        "
                                        class="size-4 rounded border-border accent-accent disabled:opacity-40"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div
            v-if="channelsConfigured.ntfy"
            data-destination="ntfy"
            class="rounded-xl border border-border bg-card p-4"
        >
            <div class="text-[14px] font-semibold">ntfy topic</div>
            <p class="mt-1 mb-3 text-[12.5px] text-muted-foreground">
                Pushes go to this topic on the configured ntfy server. Leave
                empty to disable ntfy for your account.
            </p>
            <div class="flex items-end gap-2">
                <div class="flex-1 space-y-2">
                    <Label for="ntfy_topic">Topic</Label>
                    <Input
                        id="ntfy_topic"
                        v-model="ntfyTopic"
                        name="ntfy_topic"
                        placeholder="my-mediamanager-alerts"
                    />
                    <InputError :message="$page.props.errors.ntfy_topic" />
                </div>
                <Button
                    type="button"
                    variant="outline"
                    data-test-send="ntfy"
                    :disabled="
                        !hasNtfy ||
                        ntfyUnsaved ||
                        testingChannel !== null ||
                        saving
                    "
                    @click="sendTest('ntfy')"
                >
                    {{ testingChannel === 'ntfy' ? 'Sending…' : 'Send test' }}
                </Button>
            </div>
            <p
                v-if="ntfyUnsaved"
                class="mt-2 text-[12px] text-muted-foreground"
            >
                Save before sending a test to a changed destination.
            </p>
        </div>

        <div
            data-destination="discord"
            class="rounded-xl border border-border bg-card p-4"
        >
            <div class="text-[14px] font-semibold">Discord</div>
            <p class="mt-1 mb-3 text-[12.5px] text-muted-foreground">
                Create a webhook in your Discord channel settings and paste its
                URL. Stored encrypted.
                <span v-if="hasDiscord"
                    >Current:
                    <span class="font-mono-tabular">{{
                        destinations.discord_webhook_url_hint
                    }}</span></span
                >
            </p>
            <div class="flex items-end gap-2">
                <div class="flex-1 space-y-2">
                    <Label for="discord_webhook_url">Webhook URL</Label>
                    <Input
                        id="discord_webhook_url"
                        v-model="discordWebhookUrl"
                        name="discord_webhook_url"
                        type="url"
                        :placeholder="
                            hasDiscord
                                ? 'Leave blank to keep the saved URL'
                                : 'https://discord.com/api/webhooks/…'
                        "
                        @input="discordTouched = true"
                    />
                    <InputError
                        :message="$page.props.errors.discord_webhook_url"
                    />
                </div>
                <Button
                    v-if="hasDiscord"
                    type="button"
                    variant="ghost"
                    @click="clearSecret('discord')"
                >
                    Clear
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    data-test-send="discord"
                    :disabled="!hasDiscord || testingChannel !== null || saving"
                    @click="sendTest('discord')"
                >
                    {{
                        testingChannel === 'discord' ? 'Sending…' : 'Send test'
                    }}
                </Button>
            </div>
        </div>

        <div
            v-if="channelsConfigured.telegram"
            data-destination="telegram"
            class="rounded-xl border border-border bg-card p-4"
        >
            <div class="text-[14px] font-semibold">Telegram</div>
            <p class="mt-1 mb-3 text-[12.5px] text-muted-foreground">
                Start a chat with the MediaManager bot (or add it to a group)
                and enter the chat id. Group ids are negative.
            </p>
            <div class="flex items-end gap-2">
                <div class="flex-1 space-y-2">
                    <Label for="telegram_chat_id">Chat id</Label>
                    <Input
                        id="telegram_chat_id"
                        v-model="telegramChatId"
                        name="telegram_chat_id"
                        placeholder="-1001234567890"
                    />
                    <InputError
                        :message="$page.props.errors.telegram_chat_id"
                    />
                </div>
                <Button
                    type="button"
                    variant="outline"
                    data-test-send="telegram"
                    :disabled="
                        !hasTelegram ||
                        telegramUnsaved ||
                        testingChannel !== null ||
                        saving
                    "
                    @click="sendTest('telegram')"
                >
                    {{
                        testingChannel === 'telegram' ? 'Sending…' : 'Send test'
                    }}
                </Button>
            </div>
            <p
                v-if="telegramUnsaved"
                class="mt-2 text-[12px] text-muted-foreground"
            >
                Save before sending a test to a changed destination.
            </p>
        </div>

        <div
            data-destination="webhook"
            class="rounded-xl border border-border bg-card p-4"
        >
            <div class="text-[14px] font-semibold">Webhook</div>
            <p class="mt-1 mb-3 text-[12.5px] text-muted-foreground">
                MediaManager POSTs JSON (<span class="font-mono-tabular"
                    >event, severity, title, message, url, sent_at</span
                >) to this URL. With a secret, each request carries
                <span class="font-mono-tabular"
                    >X-MediaManager-Signature: sha256=HMAC(body)</span
                >.
            </p>
            <div class="grid gap-3 md:grid-cols-[1fr_1fr_auto] md:items-end">
                <div class="space-y-2">
                    <Label for="webhook_url">URL</Label>
                    <Input
                        id="webhook_url"
                        v-model="webhookUrl"
                        name="webhook_url"
                        type="url"
                        placeholder="https://example.com/hooks/mediamanager"
                    />
                    <InputError :message="$page.props.errors.webhook_url" />
                </div>
                <div class="space-y-2">
                    <Label for="webhook_secret">Signing secret</Label>
                    <div class="flex gap-2">
                        <Input
                            id="webhook_secret"
                            v-model="webhookSecret"
                            name="webhook_secret"
                            type="password"
                            :placeholder="
                                destinations.webhook_secret_set
                                    ? 'Leave blank to keep the saved secret'
                                    : 'Optional'
                            "
                            @input="webhookSecretTouched = true"
                        />
                        <Button
                            v-if="destinations.webhook_secret_set"
                            type="button"
                            variant="ghost"
                            @click="clearSecret('webhookSecret')"
                        >
                            Clear
                        </Button>
                    </div>
                    <InputError :message="$page.props.errors.webhook_secret" />
                </div>
                <Button
                    type="button"
                    variant="outline"
                    data-test-send="webhook"
                    :disabled="
                        !hasWebhook ||
                        webhookUnsaved ||
                        testingChannel !== null ||
                        saving
                    "
                    @click="sendTest('webhook')"
                >
                    {{
                        testingChannel === 'webhook' ? 'Sending…' : 'Send test'
                    }}
                </Button>
            </div>
            <p
                v-if="webhookUnsaved"
                class="mt-2 text-[12px] text-muted-foreground"
            >
                Save before sending a test to a changed destination.
            </p>
        </div>

        <InputError :message="$page.props.errors.test_channel" />

        <div class="flex justify-end">
            <Button :disabled="saving" @click="save">
                {{ saving ? 'Saving…' : 'Save preferences' }}
            </Button>
        </div>
    </div>
</template>
