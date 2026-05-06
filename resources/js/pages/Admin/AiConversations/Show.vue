<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Archive,
    ArchiveRestore,
    ArrowLeft,
    Sparkles,
    Trash2,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AiConversationController from '@/actions/App/Http/Controllers/Admin/AiConversationController';
import { InitialsAvatar, Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { dashboard } from '@/routes';

interface MessageRow {
    role: string;
    text: string;
    tool_calls: Array<Record<string, unknown>>;
    tool_results: Array<Record<string, unknown>>;
    created_at: string;
}

interface ConversationDetail {
    id: string;
    title: string;
    archived_at: string | null;
    created_at: string;
    updated_at: string;
    user: { id: number; name: string; email: string } | null;
}

const props = defineProps<{
    conversation: ConversationDetail;
    messages: MessageRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            {
                title: 'AI Conversations',
                href: AiConversationController.index.url(),
            },
        ],
    },
});

const confirming = ref(false);
const deleting = ref(false);

const messageCount = computed(() => props.messages.length);

function archive(): void {
    router.post(
        AiConversationController.archive.url(props.conversation.id),
        {},
        { preserveScroll: true },
    );
}

function unarchive(): void {
    router.post(
        AiConversationController.unarchive.url(props.conversation.id),
        {},
        { preserveScroll: true },
    );
}

function destroy(): void {
    deleting.value = true;
    router.delete(AiConversationController.destroy.url(props.conversation.id), {
        onFinish: () => {
            deleting.value = false;
            confirming.value = false;
        },
    });
}

function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString([], {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}
</script>

<template>
    <Head :title="conversation.title" />

    <div class="flex max-w-4xl flex-col gap-4 p-5">
        <div class="flex items-start justify-between gap-3">
            <div>
                <a
                    :href="AiConversationController.index.url()"
                    class="mb-1.5 inline-flex items-center gap-1 text-[13px] text-muted-foreground hover:underline"
                >
                    <ArrowLeft class="size-3.5" />
                    All conversations
                </a>
                <h1
                    class="text-[20px] leading-tight font-semibold tracking-tight"
                >
                    {{ conversation.title }}
                </h1>
                <div
                    class="mt-1 flex flex-wrap items-center gap-2 text-[12px] text-muted-foreground"
                >
                    <span v-if="conversation.user">
                        Owner:
                        <span class="text-foreground">{{
                            conversation.user.name
                        }}</span>
                        <span class="text-fg-subtle"
                            >({{ conversation.user.email }})</span
                        >
                    </span>
                    <span v-else class="text-fg-subtle">No owner.</span>
                    <span class="text-fg-subtle">·</span>
                    <span
                        >Created {{ formatDate(conversation.created_at) }}</span
                    >
                    <span class="text-fg-subtle">·</span>
                    <span
                        >Updated {{ formatDate(conversation.updated_at) }}</span
                    >
                    <span class="text-fg-subtle">·</span>
                    <span>{{ messageCount }} messages</span>
                    <Pill
                        v-if="conversation.archived_at"
                        variant="warn"
                        class="text-[10px]"
                    >
                        Archived {{ formatDate(conversation.archived_at) }}
                    </Pill>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    v-if="!conversation.archived_at"
                    type="button"
                    size="sm"
                    variant="outline"
                    class="h-8 gap-1.5 text-xs"
                    @click="archive"
                >
                    <Archive class="size-3.5" />
                    Archive
                </Button>
                <Button
                    v-else
                    type="button"
                    size="sm"
                    variant="outline"
                    class="h-8 gap-1.5 text-xs"
                    @click="unarchive"
                >
                    <ArchiveRestore class="size-3.5" />
                    Unarchive
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="destructive"
                    class="h-8 gap-1.5 text-xs"
                    @click="confirming = true"
                >
                    <Trash2 class="size-3.5" />
                    Delete
                </Button>
            </div>
        </div>

        <div
            class="flex flex-col gap-4 rounded-xl border border-border bg-card p-5"
        >
            <div
                v-if="messages.length === 0"
                class="text-center text-sm text-muted-foreground"
            >
                No messages in this conversation.
            </div>
            <div
                v-for="(message, i) in messages"
                :key="i"
                class="flex items-start gap-3"
            >
                <InitialsAvatar
                    v-if="message.role === 'user'"
                    :name="conversation.user?.name ?? 'User'"
                    :size="26"
                />
                <span
                    v-else
                    class="inline-flex size-[26px] items-center justify-center rounded-full border border-accent/28 bg-accent/18 text-accent"
                >
                    <Sparkles class="size-3.5" />
                </span>
                <div class="min-w-0 flex-1">
                    <div
                        class="mb-1 flex items-center gap-2 text-[11.5px] text-muted-foreground"
                    >
                        <span class="capitalize">{{ message.role }}</span>
                        <span class="text-fg-subtle">·</span>
                        <span>{{ formatDate(message.created_at) }}</span>
                    </div>
                    <div
                        v-if="message.text"
                        class="text-[14px] leading-relaxed whitespace-pre-wrap"
                    >
                        {{ message.text }}
                    </div>
                    <div
                        v-if="message.tool_calls.length > 0"
                        class="mt-2 rounded-md border border-border bg-bg-elev p-2 text-[11.5px] text-muted-foreground"
                    >
                        <div
                            class="mb-1 text-[10px] font-semibold tracking-wide uppercase"
                        >
                            Tool calls
                        </div>
                        <pre
                            class="font-mono-tabular text-[11px] whitespace-pre-wrap"
                            >{{
                                JSON.stringify(message.tool_calls, null, 2)
                            }}</pre
                        >
                    </div>
                    <div
                        v-if="message.tool_results.length > 0"
                        class="mt-2 rounded-md border border-border bg-bg-elev p-2 text-[11.5px] text-muted-foreground"
                    >
                        <div
                            class="mb-1 text-[10px] font-semibold tracking-wide uppercase"
                        >
                            Tool results
                        </div>
                        <pre
                            class="font-mono-tabular text-[11px] whitespace-pre-wrap"
                            >{{
                                JSON.stringify(message.tool_results, null, 2)
                            }}</pre
                        >
                    </div>
                </div>
            </div>
        </div>

        <Dialog v-model:open="confirming">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete this conversation?</DialogTitle>
                    <DialogDescription>
                        This permanently removes the conversation and all
                        {{ messageCount }} messages. This cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="deleting"
                        @click="confirming = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        :disabled="deleting"
                        @click="destroy"
                    >
                        Delete permanently
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
