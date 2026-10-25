<script setup lang="ts">
import { Check, MoreHorizontal, Pencil, Sparkles, X } from '@lucide/vue';
import { nextTick, ref, useTemplateRef } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useAiChat } from '@/composables/useAiChat';
import { cn } from '@/lib/utils';
import ChatModelOptions from './ChatModelOptions.vue';
import ChatModelPicker from './ChatModelPicker.vue';
import ConversationPicker from './ConversationPicker.vue';
import type { ChatMode, ChatOverride, ModelOptions } from './types';

const props = defineProps<{
    title: string;
    isSheet: boolean;
    modelOptions: ModelOptions | null;
    /** The model can't change right now (a turn or a save is in flight). */
    modelLocked: boolean;
}>();

const mode = defineModel<ChatMode>('mode', { required: true });

const override = defineModel<ChatOverride>('override', { required: true });

const emit = defineEmits<{
    (e: 'select', id: string): void;
    (e: 'new'): void;
    (e: 'overrideChange', value: ChatOverride): void;
}>();

const { activeConversationId, renameConversation } = useAiChat();

/** The phone header's ⋯ panel (mode and model). */
const optionsOpen = ref(false);

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
                isSheet ? 'px-4 py-3 max-sm:pr-14' : 'px-6 py-3.5',
            )
        "
    >
        <div
            :class="
                cn(
                    'flex items-center gap-2.5 max-sm:min-w-0',
                    renaming ? 'max-sm:flex-1' : 'max-sm:shrink-0',
                )
            "
        >
            <Sparkles class="size-4 text-accent" />
            <span
                v-if="!renaming"
                :title="title"
                class="max-w-[360px] truncate font-semibold max-sm:hidden"
                data-chat-title
            >
                {{ title }}
            </span>
            <Input
                v-else
                ref="renameInput"
                v-model="renameDraft"
                class="h-7 w-44 text-sm max-sm:w-auto max-sm:min-w-0 max-sm:flex-1"
                data-chat-rename-input
                @keydown="onRenameKey"
            />
            <Button
                v-if="renaming"
                variant="ghost"
                size="sm"
                class="size-7 p-0"
                data-chat-rename-save
                aria-label="Save title"
                @click="commitRename"
            >
                <Check class="size-3.5" />
            </Button>
            <Button
                v-if="renaming"
                variant="ghost"
                size="sm"
                class="size-7 p-0"
                aria-label="Cancel rename"
                data-chat-rename-cancel
                @click="cancelRename"
            >
                <X class="size-3.5" />
            </Button>
            <Button
                v-else-if="activeConversationId"
                variant="ghost"
                size="sm"
                class="size-7 p-0 text-muted-foreground hover:text-foreground max-sm:hidden"
                title="Rename conversation"
                data-chat-rename
                @click="startRename"
            >
                <Pencil class="size-3.5" />
            </Button>
        </div>
        <div class="flex min-w-0 items-center gap-1.5">
            <ConversationPicker
                :class="cn('max-sm:min-w-0', renaming && 'max-sm:hidden')"
                @select="emit('select', $event)"
                @new="emit('new')"
                @rename="startRename"
            />
            <div class="hidden items-center gap-1.5 sm:contents">
                <ChatModelPicker
                    v-model="override"
                    :options="modelOptions"
                    :disabled="modelLocked"
                    @change="emit('overrideChange', $event)"
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
            <Popover v-model:open="optionsOpen">
                <PopoverTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        class="size-8 sm:hidden"
                        aria-label="Chat options"
                        data-chat-options
                    >
                        <MoreHorizontal class="size-4" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    align="end"
                    class="flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-4"
                    data-chat-options-panel
                >
                    <div class="flex flex-col gap-1.5">
                        <span class="text-xs font-medium text-muted-foreground">
                            Mode
                        </span>
                        <div
                            class="flex gap-1 rounded-md border border-border bg-bg-elev p-0.5"
                            role="group"
                            aria-label="Chat mode"
                        >
                            <button
                                v-for="m in ['advisory', 'executive'] as const"
                                :key="m"
                                type="button"
                                :aria-pressed="mode === m"
                                :data-chat-menu-mode="m"
                                :class="
                                    cn(
                                        'touch-target inline-flex h-9 flex-1 items-center justify-center rounded px-2 text-xs font-medium capitalize transition-colors',
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
                    <ChatModelOptions
                        v-model="override"
                        :options="modelOptions"
                        :disabled="modelLocked"
                        @change="emit('overrideChange', $event)"
                    />
                </PopoverContent>
            </Popover>
        </div>
    </div>
</template>
