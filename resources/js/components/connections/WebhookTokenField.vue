<script setup lang="ts">
import { ClipboardCopy, Eye, EyeOff, RefreshCw } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
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

/**
 * Webhook token input with reveal, copy and generate buttons. The default
 * slot is the help text between the input row and the validation error.
 */
defineProps<{
    placeholder: string;
    autocomplete?: string;
    error?: string;
}>();

const webhookToken = ref('');
const copied = ref(false);
const tokenVisible = ref(false);

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
</script>

<template>
    <div class="space-y-2">
        <Label for="webhook_token">Webhook Token</Label>
        <div class="flex gap-2">
            <Input
                id="webhook_token"
                v-model="webhookToken"
                name="webhook_token"
                :autocomplete="autocomplete"
                :type="tokenVisible ? 'text' : 'password'"
                :placeholder="placeholder"
            />
            <TooltipProvider :delay-duration="0">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            :disabled="!webhookToken"
                            data-webhook-token-toggle
                            @click="tokenVisible = !tokenVisible"
                        >
                            <EyeOff v-if="tokenVisible" class="size-4" />
                            <Eye v-else class="size-4" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>{{
                        tokenVisible ? 'Hide token' : 'Show token'
                    }}</TooltipContent>
                </Tooltip>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            :disabled="!webhookToken"
                            data-webhook-token-copy
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
                            data-webhook-token-generate
                            @click="generateWebhookToken"
                        >
                            <RefreshCw class="size-4" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Generate token</TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </div>
        <slot />
        <InputError :message="error" />
    </div>
</template>
