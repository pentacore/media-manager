<script setup lang="ts">
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Toggle } from '@/components/mm';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export interface DestinationOption {
    value: string;
    label: string;
}

const props = withDefaults(
    defineProps<{
        idPrefix: string;
        errors: Record<string, string | undefined>;
        channels: DestinationOption[];
        severities: DestinationOption[];
        initialChannel?: string;
        initialLabel?: string;
        initialEnabled?: boolean;
        initialSeverity?: string;
        initialConfig?: Record<string, string | null>;
        editing?: boolean;
    }>(),
    {
        initialChannel: undefined,
        initialLabel: '',
        initialEnabled: true,
        initialSeverity: 'info',
        initialConfig: () => ({}),
        editing: false,
    },
);

const channel = ref(
    props.initialChannel ?? props.channels[0]?.value ?? 'discord',
);
const enabled = ref(props.initialEnabled);
const severity = ref(props.initialSeverity);

const CHANNEL_HINTS: Record<string, string> = {
    ntfy: 'Topic on the configured ntfy server.',
    discord:
        'Webhook URL from the Discord channel settings. Stored encrypted; leave blank when editing to keep it.',
    telegram: 'Chat id the bot posts to. Group ids are negative.',
    webhook:
        'MediaManager POSTs JSON (event, severity, title, message, url, sent_at). With a secret, requests carry X-MediaManager-Signature: sha256=HMAC(body).',
};

const hint = computed(() => CHANNEL_HINTS[channel.value] ?? '');
</script>

<template>
    <div class="space-y-4">
        <div class="space-y-2">
            <Label :for="`${idPrefix}-channel`">Channel</Label>
            <Select
                :id="`${idPrefix}-channel`"
                v-model="channel"
                :disabled="editing"
            >
                <SelectTrigger
                    class="h-9 w-full text-sm"
                    :data-destination-channel="idPrefix"
                >
                    <SelectValue placeholder="Pick a channel" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in channels"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <input type="hidden" name="channel" :value="channel" />
            <InputError :message="errors.channel" />
        </div>

        <div class="space-y-2">
            <Label :for="`${idPrefix}-label`">Label</Label>
            <Input
                :id="`${idPrefix}-label`"
                name="label"
                :default-value="initialLabel"
                placeholder="Ops channel"
                maxlength="60"
            />
            <InputError :message="errors.label" />
        </div>

        <p class="text-[12px] text-muted-foreground">{{ hint }}</p>

        <div v-if="channel === 'ntfy'" class="space-y-2">
            <Label :for="`${idPrefix}-topic`">Topic</Label>
            <Input
                :id="`${idPrefix}-topic`"
                name="config[topic]"
                :default-value="initialConfig.topic ?? ''"
                placeholder="mediamanager-alerts"
            />
            <InputError :message="errors['config.topic']" />
        </div>

        <div v-if="channel === 'discord'" class="space-y-2">
            <Label :for="`${idPrefix}-url`">Webhook URL</Label>
            <Input
                :id="`${idPrefix}-url`"
                name="config[url]"
                type="url"
                :placeholder="
                    editing
                        ? 'Leave blank to keep the saved URL'
                        : 'https://discord.com/api/webhooks/…'
                "
            />
            <InputError :message="errors['config.url']" />
        </div>

        <div v-if="channel === 'telegram'" class="space-y-2">
            <Label :for="`${idPrefix}-chat`">Chat id</Label>
            <Input
                :id="`${idPrefix}-chat`"
                name="config[chat_id]"
                :default-value="initialConfig.chat_id ?? ''"
                placeholder="-1001234567890"
            />
            <InputError :message="errors['config.chat_id']" />
        </div>

        <template v-if="channel === 'webhook'">
            <div class="space-y-2">
                <Label :for="`${idPrefix}-url`">URL</Label>
                <Input
                    :id="`${idPrefix}-url`"
                    name="config[url]"
                    type="url"
                    :default-value="initialConfig.url ?? ''"
                    placeholder="https://example.com/hooks/mediamanager"
                />
                <InputError :message="errors['config.url']" />
            </div>
            <div class="space-y-2">
                <Label :for="`${idPrefix}-secret`">Signing secret</Label>
                <Input
                    :id="`${idPrefix}-secret`"
                    name="config[secret]"
                    type="password"
                    :placeholder="
                        editing
                            ? 'Leave blank to keep the saved secret'
                            : 'Optional'
                    "
                />
                <InputError :message="errors['config.secret']" />
            </div>
        </template>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="space-y-2">
                <Label :for="`${idPrefix}-severity`">Minimum severity</Label>
                <Select :id="`${idPrefix}-severity`" v-model="severity">
                    <SelectTrigger class="h-9 w-full text-sm">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in severities"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <input type="hidden" name="min_severity" :value="severity" />
                <InputError :message="errors.min_severity" />
            </div>
            <div class="space-y-2">
                <Label>Enabled</Label>
                <div>
                    <Toggle
                        v-model="enabled"
                        :label="enabled ? 'Enabled' : 'Disabled'"
                        :data-destination-enabled="idPrefix"
                    />
                    <input
                        type="hidden"
                        name="is_enabled"
                        :value="enabled ? '1' : '0'"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
