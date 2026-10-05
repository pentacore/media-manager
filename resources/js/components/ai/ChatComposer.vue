<script setup lang="ts">
import { ArrowRight, Paperclip, Square } from '@lucide/vue';
import { nextTick, ref, useTemplateRef } from 'vue';
import { ChatTemplatePicker } from '@/components/chat-templates';
import type { ChatTemplate } from '@/components/chat-templates';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import AttachmentChips from './AttachmentChips.vue';
import type { ChatMode, ComposerSubmission, PendingFile } from './types';

const props = defineProps<{
    mode: ChatMode;
    isSheet: boolean;
    sending: boolean;
    /** A streamed reply is in flight and can be stopped. */
    canStop: boolean;
    error: string | null;
}>();

const emit = defineEmits<{
    (e: 'send', submission: ComposerSubmission): void;
    (e: 'stop'): void;
    (e: 'template', template: ChatTemplate): void;
}>();

/** Matches the server's chat attachment rules (count and extensions). */
const MAX_ATTACHMENTS = 3;
const ATTACHMENT_EXTENSIONS = [
    'png',
    'jpg',
    'jpeg',
    'webp',
    'gif',
    'txt',
    'log',
    'json',
    'pdf',
];

const input = ref('');
const pendingFiles = ref<PendingFile[]>([]);
let nextFileKey = 0;

const inputRef = useTemplateRef<HTMLTextAreaElement>('inputArea');
const fileInput = useTemplateRef<HTMLInputElement>('fileInput');

/**
 * Queue picked or dropped files for the next turn, keeping at most three and
 * only the types the server accepts. Images get an object-URL preview.
 */
function addFiles(list: FileList | null): void {
    if (!list) {
        return;
    }

    for (const file of Array.from(list)) {
        if (pendingFiles.value.length >= MAX_ATTACHMENTS) {
            break;
        }

        const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

        if (!ATTACHMENT_EXTENSIONS.includes(extension)) {
            continue;
        }

        pendingFiles.value.push({
            key: `file-${nextFileKey++}`,
            file,
            previewUrl: file.type.startsWith('image/')
                ? URL.createObjectURL(file)
                : undefined,
        });
    }
}

function removeFile(key: string): void {
    const pending = pendingFiles.value.find((p) => p.key === key);

    if (pending?.previewUrl) {
        URL.revokeObjectURL(pending.previewUrl);
    }

    pendingFiles.value = pendingFiles.value.filter((p) => p.key !== key);
}

function onFileInputChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    addFiles(target.files);
    target.value = '';
}

/**
 * Hand the typed message and queued files to the panel, then clear the
 * composer. The object URLs stay alive so the sent bubble keeps its previews.
 */
function submit(): void {
    const text = input.value.trim();

    if (!text || props.sending) {
        return;
    }

    emit('send', { text, pendingFiles: pendingFiles.value });
    pendingFiles.value = [];
    input.value = '';
}

function onKey(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        submit();
    }
}

/**
 * Put rendered template text into the composer: replace an empty draft,
 * otherwise append it after a blank line so nothing typed is lost.
 */
function insertText(text: string): void {
    const draft = input.value.replace(/\s+$/, '');

    input.value = draft === '' ? text : `${draft}\n\n${text}`;
    void nextTick(() => inputRef.value?.focus());
}

defineExpose({
    focus: (): void => inputRef.value?.focus(),
    insertText,
});
</script>

<template>
    <div
        :class="
            cn('border-t border-border bg-bg-elev', isSheet ? 'p-3' : 'p-5')
        "
    >
        <div
            v-if="error"
            class="mb-2 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
            data-chat-error
        >
            {{ error }}
        </div>
        <AttachmentChips
            :items="
                pendingFiles.map((p) => ({
                    key: p.key,
                    name: p.file.name,
                    mime: p.file.type,
                    previewUrl: p.previewUrl,
                }))
            "
            removable
            class="mb-2"
            @remove="removeFile"
        />
        <div
            class="flex items-end gap-2.5 rounded-xl border border-border bg-card p-2.5"
            @dragover.prevent
            @drop.prevent="addFiles($event.dataTransfer?.files ?? null)"
        >
            <input
                ref="fileInput"
                type="file"
                multiple
                class="hidden"
                accept=".png,.jpg,.jpeg,.webp,.gif,.txt,.log,.json,.pdf"
                data-attachment-input
                @change="onFileInputChange"
            />
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="size-7 p-0 text-muted-foreground"
                title="Attach files"
                data-attach-button
                :disabled="sending || pendingFiles.length >= MAX_ATTACHMENTS"
                @click="fileInput?.click()"
            >
                <Paperclip class="size-3.5" />
            </Button>
            <ChatTemplatePicker @pick="emit('template', $event)" />
            <textarea
                ref="inputArea"
                v-model="input"
                :placeholder="
                    mode === 'executive'
                        ? 'Ask MediaAgent · destructive calls require approval…'
                        : 'Ask MediaAgent · advisory mode (read-only)…'
                "
                rows="1"
                class="max-h-[140px] min-h-6 flex-1 resize-none bg-transparent text-[14px] outline-none placeholder:text-fg-subtle"
                data-chat-input
                @keydown="onKey"
            />
            <Button
                v-if="canStop"
                type="button"
                size="sm"
                variant="outline"
                class="h-7 gap-1.5 text-xs"
                data-chat-stop
                @click="emit('stop')"
            >
                <Square class="size-3.5" />Stop
            </Button>
            <Button
                v-else
                type="button"
                size="sm"
                class="h-7 gap-1.5 text-xs"
                :disabled="sending || !input.trim()"
                @click="submit"
            >
                <ArrowRight class="size-3.5" />Send
            </Button>
        </div>
        <div
            class="mt-1.5 flex items-center gap-3 text-[11.5px] text-muted-foreground"
        >
            <span class="flex items-center gap-1">
                <kbd
                    class="font-mono-tabular rounded border border-border bg-card px-1 text-[10px]"
                    >↵</kbd
                >
                send
            </span>
            <span class="flex items-center gap-1">
                <kbd
                    class="font-mono-tabular rounded border border-border bg-card px-1 text-[10px]"
                    >⇧+↵</kbd
                >
                newline
            </span>
            <span v-if="!isSheet" class="ml-auto">
                {{
                    mode === 'executive'
                        ? 'Destructive tool calls queue an ActionRequest.'
                        : 'Destructive calls are short-circuited.'
                }}
            </span>
        </div>
    </div>
</template>
