<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ClipboardCopy, Wand2 } from '@lucide/vue';
import { ref } from 'vue';
import ServiceConnectionController from '@/actions/App/Http/Controllers/Admin/ServiceConnectionController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { copyToClipboard } from '@/lib/clipboard';

const props = defineProps<{
    connectionId: number;
    webhookUrl: string;
    supportsConfiguration: boolean;
}>();

const configuringWebhook = ref(false);

function configureWebhookOnService(): void {
    configuringWebhook.value = true;

    router.post(
        ServiceConnectionController.configureWebhook(props.connectionId).url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                configuringWebhook.value = false;
            },
        },
    );
}

const webhookUrlCopied = ref(false);

async function copyWebhookUrl() {
    const ok = await copyToClipboard(props.webhookUrl);

    if (!ok) {
        return;
    }

    webhookUrlCopied.value = true;
    setTimeout(() => (webhookUrlCopied.value = false), 2000);
}
</script>

<template>
    <div class="space-y-2">
        <Label>Webhook URL</Label>
        <div class="flex gap-2">
            <Input
                readonly
                :default-value="webhookUrl"
                :model-value="webhookUrl"
                class="font-mono-tabular text-xs"
                data-webhook-url
                @click="(e: Event) => (e.target as HTMLInputElement).select()"
            />
            <TooltipProvider :delay-duration="0">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            data-webhook-url-copy
                            @click="copyWebhookUrl"
                        >
                            <ClipboardCopy class="size-4" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>{{
                        webhookUrlCopied ? 'Copied!' : 'Copy webhook URL'
                    }}</TooltipContent>
                </Tooltip>
                <Tooltip v-if="supportsConfiguration">
                    <TooltipTrigger as-child>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            :disabled="configuringWebhook"
                            data-webhook-configure
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
            Paste this into the upstream service's webhook configuration — or
            click the wand button to push it automatically
            (Sonarr/Radarr/Prowlarr only). The token in the URL is in addition
            to the X-Webhook-Token header — either is accepted.
        </p>
    </div>
</template>
