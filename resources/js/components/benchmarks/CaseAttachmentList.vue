<script setup lang="ts">
import { Paperclip, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { formatBytes } from '@/lib/format';
import type { BenchmarkAttachmentEntry } from './types';

const MAX_ATTACHMENTS = 3;
const ACCEPT = '.png,.jpg,.jpeg,.webp,.gif,.txt,.log,.json,.pdf';

defineProps<{
    /** `final` for the last prompt, otherwise the history index. */
    turnKey: string;
    error?: string;
}>();

const entries = defineModel<BenchmarkAttachmentEntry[]>({ required: true });

function onPick(event: Event): void {
    const input = event.target as HTMLInputElement;
    const free = MAX_ATTACHMENTS - entries.value.length;
    const picked = Array.from(input.files ?? []).slice(0, Math.max(free, 0));

    entries.value = [
        ...entries.value,
        ...picked.map((file): BenchmarkAttachmentEntry => ({
            kind: 'new',
            file,
            name: file.name,
            size: file.size,
        })),
    ];
    input.value = '';
}

function removeEntry(index: number): void {
    entries.value = entries.value.filter((_, i) => i !== index);
}
</script>

<template>
    <div class="grid gap-1.5" :data-benchmark-attachments="turnKey">
        <div
            v-for="(entry, index) in entries"
            :key="index"
            class="flex items-center gap-2 rounded-md border border-border bg-card px-2 py-1 text-[12.5px]"
            data-benchmark-attachment
        >
            <span class="min-w-0 flex-1 truncate">{{ entry.name }}</span>
            <span class="text-muted-foreground">{{
                formatBytes(entry.size)
            }}</span>
            <Pill
                v-if="entry.kind === 'draft'"
                variant="info"
                data-benchmark-attachment-pending
                >copied on save</Pill
            >
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="size-7 text-destructive"
                :aria-label="`Remove ${entry.name}`"
                data-benchmark-attachment-remove
                @click="removeEntry(index)"
            >
                <Trash2 class="size-3.5" />
            </Button>
        </div>

        <label
            v-if="entries.length < MAX_ATTACHMENTS"
            class="inline-flex h-7 w-fit cursor-pointer items-center gap-1 rounded-md border border-input bg-background px-2 text-xs font-medium hover:bg-bg-hover"
        >
            <Paperclip class="size-3.5" />Attach file
            <input
                type="file"
                multiple
                class="sr-only"
                :accept="ACCEPT"
                data-benchmark-attachment-input
                @change="onPick"
            />
        </label>
        <InputError :message="error" />
    </div>
</template>
