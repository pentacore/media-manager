<script setup lang="ts">
import { ClipboardCopy } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { copyToClipboard } from '@/lib/clipboard';

const props = defineProps<{
    script: string;
}>();

const sabScriptCopied = ref(false);

async function copySabScript(): Promise<void> {
    const ok = await copyToClipboard(props.script);

    if (!ok) {
        return;
    }

    sabScriptCopied.value = true;
    setTimeout(() => (sabScriptCopied.value = false), 2000);
}
</script>

<template>
    <div class="space-y-3 pt-2" data-sab-script>
        <div class="flex items-end justify-between gap-2">
            <div>
                <Label>Notification script</Label>
                <p class="text-sm text-muted-foreground">
                    SABnzbd doesn't have native HTTP webhooks, but it can run a
                    notification script per event. Save this Python file into
                    your SAB
                    <code>scripts/</code> folder, then pick it under Settings →
                    Notifications. Token is embedded in the URL.
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
            >{{ script }}</pre>
    </div>
</template>
