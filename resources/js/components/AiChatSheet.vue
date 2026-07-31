<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { computed } from 'vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { useAiChat } from '@/composables/useAiChat';
import { useAiChatWidth } from '@/composables/useAiChatWidth';
import { cn } from '@/lib/utils';
import ChatPanel from './ai/ChatPanel.vue';

const page = usePage();

const isAdmin = computed(() => {
    const role = page.props.auth.user?.role;

    if (!role) {
        return false;
    }

    const value = typeof role === 'string' ? role : role.value;

    return value === 'admin';
});

const aiEnabled = computed(() =>
    Boolean(
        (page.props as unknown as { ai?: { enabled?: boolean } }).ai?.enabled,
    ),
);

const visible = computed(() => isAdmin.value && aiEnabled.value);

const { open } = useAiChat();

const {
    width,
    resizing,
    minWidth,
    maxWidth,
    startResize,
    resetWidth,
    handleKeydown,
} = useAiChatWidth();

/** Below `sm` the sheet stays full-width, so the pixel width doesn't apply. */
const isResizable = useMediaQuery('(min-width: 640px)');

const contentStyle = computed(() =>
    isResizable.value ? { width: `${width.value}px` } : undefined,
);
</script>

<template>
    <Sheet v-if="visible" v-model:open="open">
        <SheetContent
            side="right"
            :style="contentStyle"
            class="flex w-full flex-col gap-0 p-0 sm:max-w-none"
        >
            <SheetTitle class="sr-only">AI Assistant</SheetTitle>
            <SheetDescription class="sr-only">
                Chat with MediaAgent. Press Cmd or Ctrl + J to toggle. Drag the
                left edge to resize.
            </SheetDescription>
            <div
                v-if="isResizable"
                data-slot="ai-chat-resize"
                role="separator"
                aria-orientation="vertical"
                aria-label="Resize AI assistant panel"
                :aria-valuenow="width"
                :aria-valuemin="minWidth"
                :aria-valuemax="maxWidth"
                tabindex="0"
                title="Drag to resize · double-click to reset"
                :class="
                    cn(
                        'group absolute inset-y-0 left-0 z-10 flex w-2 cursor-col-resize touch-none items-center justify-center focus-visible:outline-hidden',
                        resizing && 'bg-accent/10',
                    )
                "
                @pointerdown="startResize"
                @dblclick="resetWidth"
                @keydown="handleKeydown"
            >
                <span
                    :class="
                        cn(
                            'h-10 w-0.5 rounded-full transition-colors group-hover:bg-accent group-focus-visible:bg-accent',
                            resizing ? 'bg-accent' : 'bg-border',
                        )
                    "
                />
            </div>
            <ChatPanel variant="sheet" />
        </SheetContent>
    </Sheet>
</template>
