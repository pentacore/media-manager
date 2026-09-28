<script setup lang="ts">
import { Check, Pencil, Sparkles, X } from '@lucide/vue';
import { nextTick, ref, useTemplateRef } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useAiChat } from '@/composables/useAiChat';
import { cn } from '@/lib/utils';
import ConversationPicker from './ConversationPicker.vue';
import type { ChatMode } from './types';

const props = defineProps<{
    title: string;
    isSheet: boolean;
}>();

const mode = defineModel<ChatMode>('mode', { required: true });

const emit = defineEmits<{
    (e: 'select', id: string): void;
    (e: 'new'): void;
}>();

const { activeConversationId, renameConversation } = useAiChat();

const renaming = ref(false);
const renameDraft = ref('');
const renameRef = useTemplateRef<InstanceType<typeof Input>>('renameInput');

function startRename(): void {
    if (!activeConversationId.value) {
        return;
    }

    renameDraft.value = props.title;
    renaming.value = true;
    nextTick(() =>
        (renameRef.value?.$el as HTMLInputElement | undefined)?.focus(),
    );
}

async function commitRename(): Promise<void> {
    const id = activeConversationId.value;
    const next = renameDraft.value.trim();

    if (!id || next === '') {
        renaming.value = false;

        return;
    }

    try {
        const updated = await renameConversation(id, next);
        toast.success(`Renamed to "${updated.title}"`);
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'Rename failed.');
    } finally {
        renaming.value = false;
    }
}

function cancelRename(): void {
    renaming.value = false;
}

function onRenameKey(event: KeyboardEvent): void {
    if (event.key === 'Enter') {
        event.preventDefault();
        void commitRename();
    } else if (event.key === 'Escape') {
        event.preventDefault();
        cancelRename();
    }
}
</script>

<template>
    <div
        :class="
            cn(
                'flex items-center justify-between gap-3 border-b border-border',
                isSheet ? 'px-4 py-3' : 'px-6 py-3.5',
            )
        "
    >
        <div class="flex items-center gap-2.5">
            <Sparkles class="size-4 text-accent" />
            <span
                v-if="!renaming"
                :title="title"
                class="max-w-[360px] truncate font-semibold"
                data-chat-title
            >
                {{ title }}
            </span>
            <Input
                v-else
                ref="renameInput"
                v-model="renameDraft"
                class="h-7 w-44 text-sm"
                data-chat-rename-input
                @keydown="onRenameKey"
            />
            <Button
                v-if="renaming"
                variant="ghost"
                size="sm"
                class="size-7 p-0"
                data-chat-rename-save
                @click="commitRename"
            >
                <Check class="size-3.5" />
            </Button>
            <Button
                v-if="renaming"
                variant="ghost"
                size="sm"
                class="size-7 p-0"
                @click="cancelRename"
            >
                <X class="size-3.5" />
            </Button>
            <Button
                v-else-if="activeConversationId"
                variant="ghost"
                size="sm"
                class="size-7 p-0 text-muted-foreground hover:text-foreground"
                title="Rename conversation"
                data-chat-rename
                @click="startRename"
            >
                <Pencil class="size-3.5" />
            </Button>
        </div>
        <div class="flex items-center gap-1.5">
            <ConversationPicker
                @select="emit('select', $event)"
                @new="emit('new')"
                @rename="startRename"
            />
            <div
                v-if="!isSheet"
                class="ml-2 flex items-center gap-0.5 rounded-md border border-border bg-bg-elev p-0.5"
            >
                <button
                    v-for="m in ['advisory', 'executive'] as const"
                    :key="m"
                    type="button"
                    :data-chat-mode="m"
                    :class="
                        cn(
                            'inline-flex h-6 items-center rounded px-2 text-xs font-medium transition-colors',
                            mode === m
                                ? 'bg-accent text-accent-foreground'
                                : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                        )
                    "
                    @click="mode = m"
                >
                    {{ m }}
                </button>
            </div>
        </div>
    </div>
</template>
