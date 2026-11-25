<script setup lang="ts">
import { Paperclip, Trash2 } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { formatBytes } from '@/lib/format';
import type { BenchmarkAttachmentEntry } from './types';

const ACCEPT = '.png,.jpg,.jpeg,.webp,.gif,.txt,.log,.json,.pdf';

/**
 * Raster formats a preview may open in a tab; anything else is downloaded,
 * as the server does for stored files, so a picked file never renders on
 * the app origin.
 */
const INLINE_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

const props = defineProps<{
    /** `final` for the last prompt, otherwise the history index. */
    turnKey: string;
    /** Files the turn may carry (the attachments-per-message setting). */
    max: number;
    error?: string;
}>();

const entries = defineModel<BenchmarkAttachmentEntry[]>({ required: true });
const overflow = ref(0);

/** Object URLs of the files picked but not saved yet, revoked once removed. */
const localUrls = ref(new Map<File, string>());

watch(
    entries,
    (current) => {
        const files = new Set(
            current.flatMap((entry) =>
                entry.kind === 'new' ? [entry.file] : [],
            ),
        );
        const next = new Map<File, string>();

        for (const [file, url] of localUrls.value) {
            if (files.has(file)) {
                next.set(file, url);
            } else {
                URL.revokeObjectURL(url);
            }
        }

        for (const file of files) {
            if (!next.has(file)) {
                next.set(file, URL.createObjectURL(file));
            }
        }

        localUrls.value = next;
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    for (const url of localUrls.value.values()) {
        URL.revokeObjectURL(url);
    }

    localUrls.value = new Map();
});

/** Where a row's name links: the stored file, the draft's source, or the picked file. */
function previewUrl(entry: BenchmarkAttachmentEntry): string | undefined {
    return entry.kind === 'new' ? localUrls.value.get(entry.file) : entry.url;
}

/** A picked file that is not a raster image downloads instead of opening. */
function downloadName(entry: BenchmarkAttachmentEntry): string | undefined {
    return entry.kind === 'new' && !INLINE_TYPES.includes(entry.file.type)
        ? entry.name
        : undefined;
}

function onPick(event: Event): void {
    const input = event.target as HTMLInputElement;
    const free = props.max - entries.value.length;
    const all = Array.from(input.files ?? []);
    const picked = all.slice(0, Math.max(free, 0));

    overflow.value = all.length - picked.length;

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
    overflow.value = 0;
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
            <a
                v-if="previewUrl(entry)"
                :href="previewUrl(entry)"
                :download="downloadName(entry)"
                target="_blank"
                rel="noopener"
                class="min-w-0 flex-1 truncate underline decoration-muted-foreground/40 underline-offset-2 hover:decoration-current focus-visible:decoration-current focus-visible:outline-none"
                :title="`Open ${entry.name}`"
                data-benchmark-attachment-open
                >{{ entry.name }}</a
            >
            <span v-else class="min-w-0 flex-1 truncate">{{ entry.name }}</span>
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
            v-if="entries.length < max"
            class="inline-flex h-7 w-fit cursor-pointer items-center gap-1 rounded-md border border-input bg-background px-2 text-xs font-medium focus-within:ring-2 focus-within:ring-ring hover:bg-bg-hover"
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
        <p
            v-if="overflow > 0"
            class="text-xs text-destructive"
            data-benchmark-attachment-overflow
        >
            Only {{ max }} {{ max === 1 ? 'file' : 'files' }} per turn —
            {{ overflow }} not added.
        </p>
        <InputError :message="error" />
    </div>
</template>
