<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { useAiChat } from '@/composables/useAiChat';
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
</script>

<template>
    <Sheet v-if="visible" v-model:open="open">
        <SheetContent
            side="right"
            class="flex w-full flex-col gap-0 p-0 sm:max-w-[440px]"
        >
            <SheetTitle class="sr-only">AI Assistant</SheetTitle>
            <SheetDescription class="sr-only">
                Chat with MediaAgent. Press Cmd or Ctrl + J to toggle.
            </SheetDescription>
            <ChatPanel variant="sheet" />
        </SheetContent>
    </Sheet>
</template>
