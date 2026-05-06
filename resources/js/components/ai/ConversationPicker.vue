<script setup lang="ts">
import { ChevronDown, MessageSquare, Pencil, Plus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { TimeStamp } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAiChat } from '@/composables/useAiChat';

const emit = defineEmits<{
    (e: 'select', id: string): void;
    (e: 'new'): void;
    (e: 'rename'): void;
}>();

const { activeConversationId, recent, recentLoading, refreshRecent } =
    useAiChat();

const open = ref(false);

watch(open, (next) => {
    if (next) {
        void refreshRecent();
    }
});

const activeTitle = computed(() => {
    const id = activeConversationId.value;

    if (!id) {
        return 'New chat';
    }

    return recent.value.find((c) => c.id === id)?.title ?? 'Conversation';
});

function pick(id: string): void {
    open.value = false;
    emit('select', id);
}

function newChat(): void {
    open.value = false;
    emit('new');
}

function rename(): void {
    open.value = false;
    emit('rename');
}
</script>

<template>
    <div class="flex items-center gap-1.5">
        <DropdownMenu v-model:open="open">
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-7 max-w-[360px] gap-1 px-2 text-xs"
                >
                    <MessageSquare class="size-3.5 shrink-0" />
                    <span :title="activeTitle" class="truncate">{{
                        activeTitle
                    }}</span>
                    <ChevronDown class="size-3.5 shrink-0 opacity-60" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="w-72">
                <DropdownMenuLabel
                    class="flex items-center justify-between text-[11px]"
                >
                    <span>Recent conversations</span>
                    <span v-if="recentLoading" class="text-muted-foreground"
                        >Loading…</span
                    >
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    class="cursor-pointer gap-2 text-xs"
                    @select="newChat"
                >
                    <Plus class="size-3.5" />
                    New chat
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="activeConversationId"
                    class="cursor-pointer gap-2 text-xs"
                    @select="rename"
                >
                    <Pencil class="size-3.5" />
                    Rename current
                </DropdownMenuItem>
                <template v-if="recent.length > 0">
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        v-for="convo in recent"
                        :key="convo.id"
                        class="flex cursor-pointer flex-col items-start gap-0.5 text-xs"
                        :class="
                            convo.id === activeConversationId
                                ? 'bg-accent/40'
                                : ''
                        "
                        @select="pick(convo.id)"
                    >
                        <span
                            :title="convo.title"
                            class="line-clamp-1 font-medium"
                        >
                            {{ convo.title }}
                        </span>
                        <TimeStamp
                            :iso="convo.updated_at"
                            class="text-[10.5px] text-muted-foreground"
                        />
                    </DropdownMenuItem>
                </template>
                <p
                    v-else-if="!recentLoading"
                    class="px-2 py-3 text-center text-[11px] text-muted-foreground"
                >
                    No conversations yet.
                </p>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
