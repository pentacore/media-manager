<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Check,
    Cpu,
    Paperclip,
    Pencil,
    Sparkles,
    X,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onUnmounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import { toast } from 'vue-sonner';
import AIChatController from '@/actions/App/Http/Controllers/AI/ChatController';
import { InitialsAvatar, Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { jsonRequest, useAiChat } from '@/composables/useAiChat';
import type { AgentStep, ConversationMessage } from '@/composables/useAiChat';
import { ChatStreamError, useChatStream } from '@/composables/useChatStream';
import { useMarkdown } from '@/composables/useMarkdown';
import { useWebSocket } from '@/composables/useWebSocket';
import type { ChannelLease } from '@/composables/useWebSocket';
import { cn } from '@/lib/utils';
import AttachmentChips from './AttachmentChips.vue';
import ConversationPicker from './ConversationPicker.vue';
import ReasoningBlock from './ReasoningBlock.vue';
import StepLivenessBanner from './StepLivenessBanner.vue';
import ToolCallChip from './ToolCallChip.vue';

const props = withDefaults(
    defineProps<{
        variant?: 'page' | 'sheet';
    }>(),
    { variant: 'page' },
);

interface WorkflowProposal {
    id: string;
    rationale: string;
    steps: Array<{ action: string; target: string; reason: string }>;
}

interface ChatMessage extends ConversationMessage {
    workflow?: WorkflowProposal | null;
    workflowResolved?: 'approved' | 'declined' | null;
    /**
     * Client-local monotonic key. Backend timestamps have second resolution
     * (a user message and its reply routinely collide) and Date.now() can
     * collide within a burst — colliding :key values make Vue's keyed diff
     * patch the wrong bubbles.
     */
    uid: number;
}

let nextMessageUid = 0;

function messageUid(): number {
    return nextMessageUid++;
}

const {
    activeConversationId,
    recent,
    pendingStep,
    setPendingStep,
    setActiveConversation,
    upsertConversation,
    loadConversation,
    renameConversation,
    refreshRecent,
    startNewConversation,
} = useAiChat();

const page = usePage();
const userId = computed(() => Number(page.props.auth.user?.id ?? 0));

const { render: renderMarkdown } = useMarkdown();

const { streamChat } = useChatStream();

const { acquirePrivateChannel } = useWebSocket();

const messages = ref<ChatMessage[]>([]);
const input = ref('');
const sending = ref(false);
const error = ref<string | null>(null);
const loading = ref(false);
/** Cursor for the page of history before the oldest loaded message. */
const olderCursor = ref<string | null>(null);
const loadingEarlier = ref(false);
let lastScrollTop = 0;
const mode = ref<'advisory' | 'executive'>('executive');
const renaming = ref(false);
const renameDraft = ref('');

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

interface PendingFile {
    key: string;
    file: File;
    previewUrl?: string;
}

const pendingFiles = ref<PendingFile[]>([]);
const streamingIndex = ref<number | null>(null);
let nextFileKey = 0;

const scrollRef = useTemplateRef<HTMLDivElement>('scroll');
const inputRef = useTemplateRef<HTMLTextAreaElement>('inputArea');
const renameRef = useTemplateRef<HTMLInputElement>('renameInput');
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

const activeTitle = computed<string>(() => {
    const id = activeConversationId.value;

    if (!id) {
        return 'New chat';
    }

    return recent.value.find((c) => c.id === id)?.title ?? 'Conversation';
});

const isSheet = computed(() => props.variant === 'sheet');

let activeChannelLease: ChannelLease | null = null;

function subscribeToConversation(conversationId: string | null): void {
    activeChannelLease?.release();
    activeChannelLease = null;

    if (!conversationId || userId.value === 0) {
        return;
    }

    const key = `ai-chat.${userId.value}.${conversationId}`;
    activeChannelLease = acquirePrivateChannel(key).listen(
        '.AgentStepUpdate',
        (event: {
            conversation_id: string;
            tool_name: string;
            status: 'started' | 'finished';
            occurred_at: string;
        }) => {
            const step: AgentStep = {
                conversationId: event.conversation_id,
                toolName: event.tool_name,
                status: event.status,
                occurredAt: event.occurred_at,
            };
            setPendingStep(step);
        },
    );
}

watch(
    activeConversationId,
    (id, prev) => {
        subscribeToConversation(id);

        if (id !== prev && !id) {
            messages.value = [];
            olderCursor.value = null;
        }
    },
    { immediate: true },
);

onUnmounted(() => {
    activeChannelLease?.release();
    activeChannelLease = null;
});

async function scrollToBottom(): Promise<void> {
    await nextTick();
    scrollRef.value?.scrollTo({
        top: scrollRef.value.scrollHeight,
        behavior: 'smooth',
    });
}

async function sendMessage(continuationPayload?: {
    workflow_id: string;
    workflow_action: 'approved' | 'declined';
    syntheticUserText: string;
}): Promise<void> {
    let bodyMessage: string;
    let extraBody: Record<string, unknown> = {};
    let files: File[] = [];

    if (continuationPayload) {
        bodyMessage = continuationPayload.syntheticUserText;
        extraBody = {
            workflow_id: continuationPayload.workflow_id,
            workflow_action: continuationPayload.workflow_action,
        };
    } else {
        const text = input.value.trim();

        if (!text || sending.value) {
            return;
        }

        bodyMessage = text;
        // The object URLs stay alive so the sent bubble keeps its previews.
        messages.value.push({
            role: 'user',
            text,
            ts: Date.now(),
            uid: messageUid(),
            attachments: pendingFiles.value.map((p) => ({
                id: 0,
                name: p.file.name,
                mime: p.file.type,
                url: p.previewUrl ?? '',
            })),
        });
        files = pendingFiles.value.map((p) => p.file);
        pendingFiles.value = [];
        input.value = '';
    }

    sending.value = true;
    error.value = null;
    setPendingStep(null);

    await scrollToBottom();

    try {
        if (continuationPayload) {
            await sendBlockingTurn(bodyMessage, extraBody);
        } else {
            await sendStreamingTurn(bodyMessage, files);
        }
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Unknown error';

        const last = messages.value[messages.value.length - 1];

        if (last?.role === 'assistant' && !last.text) {
            last.failed = true;
        }
    } finally {
        sending.value = false;
        streamingIndex.value = null;
        setPendingStep(null);
        await scrollToBottom();
    }
}

/**
 * The blocking JSON path used for workflow approve/decline continuations. The
 * SSE stream deliberately doesn't support continuations, so these keep posting
 * to send() and rendering the buffered response in one shot.
 */
async function sendBlockingTurn(
    bodyMessage: string,
    extraBody: Record<string, unknown>,
): Promise<void> {
    const response = await fetch(AIChatController.send.url(), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN':
                (
                    document.querySelector(
                        'meta[name="csrf-token"]',
                    ) as HTMLMetaElement | null
                )?.content ?? '',
        },
        body: JSON.stringify({
            message: bodyMessage,
            conversation_id: activeConversationId.value,
            mode: mode.value,
            ...extraBody,
        }),
    });

    if (!response.ok) {
        const data = await response
            .json()
            .catch(() => ({}) as Record<string, unknown>);
        const errMsg =
            typeof data.error === 'string'
                ? data.error
                : typeof data.message === 'string'
                  ? data.message
                  : `Request failed (${response.status})`;

        throw new Error(errMsg);
    }

    const data = (await response.json()) as {
        text: string;
        conversation_id: string | null;
        workflow: WorkflowProposal | null;
    };

    if (
        data.conversation_id &&
        data.conversation_id !== activeConversationId.value
    ) {
        setActiveConversation(data.conversation_id);
    }

    messages.value.push({
        role: 'assistant',
        text: data.text,
        ts: Date.now(),
        uid: messageUid(),
        workflow: data.workflow,
        workflowResolved: null,
    });

    if (data.conversation_id) {
        rememberConversation(data.conversation_id);
    }
}

/**
 * Stream a normal chat turn: push an empty assistant bubble, grow it from the
 * SSE text deltas, mark tool starts as pending steps, then (once the stream
 * ends) resolve the conversation id and poll for any proposed workflow.
 */
async function sendStreamingTurn(
    bodyMessage: string,
    files: File[],
): Promise<void> {
    // Read the element back out of the reactive array so mutations to `.text`
    // during streaming go through Vue's proxy and re-render the bubble.
    const index =
        messages.value.push({
            role: 'assistant',
            text: '',
            ts: Date.now(),
            uid: messageUid(),
            reasoning: '',
            toolCalls: [],
            workflow: null,
            workflowResolved: null,
        }) - 1;
    const assistantMessage = messages.value[index];
    streamingIndex.value = index;

    const knownConversationId = activeConversationId.value;

    const result = await streamChat({
        message: bodyMessage,
        conversationId: knownConversationId,
        mode: mode.value,
        attachments: files,
        onText: (accumulated) => {
            assistantMessage.text = accumulated;
        },
        onReasoning: (accumulated) => {
            assistantMessage.reasoning = accumulated;
        },
        onToolCall: (call) => {
            const calls = assistantMessage.toolCalls ?? [];
            const existing = calls.findIndex((c) => c.id === call.id);
            assistantMessage.toolCalls =
                existing === -1
                    ? [...calls, call]
                    : calls.map((c, i) => (i === existing ? call : c));
            setPendingStep({
                conversationId: activeConversationId.value ?? '',
                toolName: call.name,
                status: call.status === 'running' ? 'started' : 'finished',
                occurredAt: new Date().toISOString(),
            });
        },
    }).catch((e: unknown) => {
        // A failed turn the server stored still belongs to a conversation
        // the user can continue — adopt it so a retry doesn't start over.
        if (e instanceof ChatStreamError && e.conversationId) {
            adoptConversation(e.conversationId, knownConversationId);
        }

        throw e;
    });

    // RUN_STARTED/RUN_FINISHED carry the conversation id as `threadId`: for an
    // existing conversation it echoes what we sent, for a brand-new one it is
    // the id minted for the turn. Adopt it directly — no recency guessing.
    const conversationId = result.conversationId;

    if (conversationId) {
        adoptConversation(conversationId, knownConversationId);
    }

    if (conversationId) {
        const pending = await jsonRequest<{
            workflow: WorkflowProposal | null;
        }>(
            'GET',
            AIChatController.pendingWorkflow.url({
                query: { conversation_id: conversationId },
            }),
        );
        assistantMessage.workflow = pending.workflow;
    }
}

function adoptConversation(
    conversationId: string,
    knownConversationId: string | null,
): void {
    if (conversationId !== knownConversationId) {
        setActiveConversation(conversationId);
    }

    rememberConversation(conversationId);
}

/**
 * Keep the recent-conversation picker in sync after a completed turn and pull
 * the queued auto-generated title once it lands.
 */
function rememberConversation(conversationId: string): void {
    upsertConversation({
        id: conversationId,
        title:
            messages.value.find((m) => m.role === 'user')?.text.slice(0, 60) ??
            'New chat',
        updated_at: new Date().toISOString(),
    });
    // Pull the freshly-generated auto-title (queued) when it lands.
    void refreshRecent(true);
}

function approveWorkflow(message: ChatMessage): void {
    void resolveWorkflow(
        message,
        'approved',
        'I approve the proposed workflow.',
    );
}

function declineWorkflow(message: ChatMessage): void {
    void resolveWorkflow(
        message,
        'declined',
        'I decline the proposed workflow.',
    );
}

async function resolveWorkflow(
    message: ChatMessage,
    action: 'approved' | 'declined',
    syntheticUserText: string,
): Promise<void> {
    if (!message.workflow || message.workflowResolved) {
        return;
    }

    // Optimistically hide the buttons so a double-click can't submit twice,
    // but restore them if the continuation request fails — the backend
    // workflow is still `proposed`, and without the buttons the proposal
    // would be permanently stranded showing a false "Approved."/"Declined."
    message.workflowResolved = action;

    await sendMessage({
        workflow_id: message.workflow.id,
        workflow_action: action,
        syntheticUserText,
    });

    if (error.value !== null) {
        message.workflowResolved = null;
    }
}

function newConversation(): void {
    startNewConversation();
    messages.value = [];
    olderCursor.value = null;
    error.value = null;
    inputRef.value?.focus();
}

async function pickConversation(id: string): Promise<void> {
    if (id === activeConversationId.value) {
        return;
    }

    setActiveConversation(id);
    messages.value = [];
    olderCursor.value = null;
    error.value = null;
    loading.value = true;

    try {
        const data = await loadConversation(id);
        messages.value = data.messages.map((m) => ({
            ...m,
            uid: messageUid(),
        }));
        olderCursor.value = data.next_cursor;

        // Persisted messages carry no workflow payload, so an unresolved
        // proposal would lose its approve/decline buttons on reload. Reattach
        // any still-pending workflow to the last assistant message.
        const pending = await jsonRequest<{
            workflow: WorkflowProposal | null;
        }>(
            'GET',
            AIChatController.pendingWorkflow.url({
                query: { conversation_id: id },
            }),
        );

        if (pending.workflow) {
            const lastAssistant = [...messages.value]
                .reverse()
                .find((m) => m.role === 'assistant');

            if (lastAssistant) {
                lastAssistant.workflow = pending.workflow;
                lastAssistant.workflowResolved = null;
            }
        }
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Failed to load.';
    } finally {
        loading.value = false;
        await scrollToBottom();
    }
}

/**
 * Prepend the page of history before the oldest loaded message, keeping the
 * viewport anchored on what the user was reading.
 */
async function loadEarlier(): Promise<void> {
    const conversationId = activeConversationId.value;
    const cursor = olderCursor.value;

    if (!conversationId || !cursor || loadingEarlier.value) {
        return;
    }

    loadingEarlier.value = true;

    try {
        const page = await loadConversation(conversationId, cursor);

        if (activeConversationId.value !== conversationId) {
            return;
        }

        const before = scrollRef.value?.scrollHeight ?? 0;
        messages.value = [
            ...page.messages.map((m) => ({ ...m, uid: messageUid() })),
            ...messages.value,
        ];
        olderCursor.value = page.next_cursor;

        await nextTick();

        if (scrollRef.value) {
            scrollRef.value.scrollTop += scrollRef.value.scrollHeight - before;
        }
    } catch (e) {
        toast.error(
            e instanceof Error ? e.message : 'Failed to load earlier messages.',
        );
    } finally {
        loadingEarlier.value = false;
    }
}

/**
 * Auto-load older history when the user scrolls up to the top of the thread.
 * Only upward scrolls count, so the smooth scroll-to-bottom after opening a
 * conversation never triggers it.
 */
function onThreadScroll(): void {
    const scrollTop = scrollRef.value?.scrollTop ?? 0;
    const scrolledUp = scrollTop < lastScrollTop;
    lastScrollTop = scrollTop;

    if (
        scrolledUp &&
        scrollTop < 40 &&
        olderCursor.value &&
        !loadingEarlier.value &&
        !loading.value
    ) {
        void loadEarlier();
    }
}

function startRename(): void {
    if (!activeConversationId.value) {
        return;
    }

    renameDraft.value = activeTitle.value;
    renaming.value = true;
    nextTick(() => renameRef.value?.focus());
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

function onKey(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        void sendMessage();
    }
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
                'flex min-h-0 flex-col',
                isSheet ? 'h-full' : 'h-[calc(100vh-3.25rem)]',
            )
        "
    >
        <!-- Header -->
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
                    :title="activeTitle"
                    class="max-w-[360px] truncate font-semibold"
                >
                    {{ activeTitle }}
                </span>
                <Input
                    v-else
                    ref="renameInput"
                    v-model="renameDraft"
                    class="h-7 w-44 text-sm"
                    @keydown="onRenameKey"
                />
                <Button
                    v-if="renaming"
                    variant="ghost"
                    size="sm"
                    class="size-7 p-0"
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
                    @click="startRename"
                >
                    <Pencil class="size-3.5" />
                </Button>
            </div>
            <div class="flex items-center gap-1.5">
                <ConversationPicker
                    @select="pickConversation"
                    @new="newConversation"
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

        <!-- Thread -->
        <div
            ref="scroll"
            :class="
                cn(
                    'flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto',
                    isSheet ? 'px-4 py-4' : 'px-6 py-5',
                )
            "
            data-chat-thread
            @scroll="onThreadScroll"
        >
            <button
                v-if="olderCursor && !loading"
                type="button"
                class="self-center text-[12px] text-muted-foreground hover:text-foreground disabled:opacity-60"
                :disabled="loadingEarlier"
                data-load-earlier
                @click="loadEarlier"
            >
                {{
                    loadingEarlier
                        ? 'Loading earlier messages…'
                        : 'Load earlier messages'
                }}
            </button>

            <div
                v-if="loading"
                class="flex h-full items-center justify-center text-sm text-muted-foreground"
            >
                Loading conversation…
            </div>

            <div
                v-else-if="messages.length === 0"
                class="flex h-full flex-col items-center justify-center gap-2 text-fg-subtle"
            >
                <Sparkles class="size-6" />
                <p class="max-w-[420px] text-center text-sm">
                    Ask about your library, request actions, or check service
                    health. Destructive tool calls queue an ActionRequest in
                    executive mode.
                </p>
            </div>

            <div
                v-for="m in messages"
                :key="m.uid"
                :class="
                    cn(
                        'flex items-start gap-3',
                        isSheet ? 'max-w-full' : 'max-w-[720px]',
                    )
                "
            >
                <InitialsAvatar
                    v-if="m.role === 'user'"
                    name="you"
                    :size="26"
                />
                <span
                    v-else
                    class="inline-flex size-[26px] items-center justify-center rounded-full border border-accent/28 bg-accent/18 text-accent"
                >
                    <Sparkles class="size-3.5" />
                </span>

                <div class="min-w-0 flex-1">
                    <div class="mb-1 text-[11.5px] text-muted-foreground">
                        {{ m.role === 'user' ? 'You' : 'MediaAgent' }}
                    </div>
                    <div
                        v-if="m.workflow"
                        class="mb-2 rounded-lg border border-border bg-bg-elev p-2.5"
                    >
                        <div class="mb-1.5 flex items-center justify-between">
                            <span class="flex items-center gap-1.5">
                                <Cpu class="size-3.5 text-fg-subtle" />
                                <span class="text-[12px] font-medium">
                                    Proposed workflow
                                </span>
                                <Pill
                                    :variant="
                                        m.workflowResolved === 'approved'
                                            ? 'ok'
                                            : m.workflowResolved === 'declined'
                                              ? 'danger'
                                              : 'warn'
                                    "
                                    class="text-[10px]"
                                >
                                    {{ m.workflowResolved ?? 'awaiting' }}
                                </Pill>
                            </span>
                        </div>
                        <p class="mb-2 text-[12.5px] text-muted-foreground">
                            {{ m.workflow.rationale }}
                        </p>
                        <ol
                            class="mb-3 list-inside list-decimal space-y-1 text-[11.5px] text-muted-foreground"
                        >
                            <li v-for="(step, i) in m.workflow.steps" :key="i">
                                <span
                                    class="font-mono-tabular text-foreground"
                                    >{{ step.action }}</span
                                >
                                on
                                <span class="font-medium">{{
                                    step.target
                                }}</span>
                                — {{ step.reason }}
                            </li>
                        </ol>
                        <div v-if="!m.workflowResolved" class="flex gap-2">
                            <Button
                                size="sm"
                                class="h-7 text-xs"
                                :disabled="sending"
                                @click="approveWorkflow(m)"
                            >
                                Approve
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                class="h-7 text-xs"
                                :disabled="sending"
                                @click="declineWorkflow(m)"
                            >
                                Decline
                            </Button>
                        </div>
                        <p v-else class="text-[11.5px] text-muted-foreground">
                            {{
                                m.workflowResolved === 'approved'
                                    ? 'Approved.'
                                    : 'Declined.'
                            }}
                        </p>
                    </div>
                    <AttachmentChips
                        v-if="m.attachments?.length"
                        :items="
                            m.attachments.map((a, i) => ({
                                key: `${m.uid}-${i}`,
                                name: a.name,
                                mime: a.mime,
                                url: a.url || undefined,
                            }))
                        "
                        class="mb-1.5"
                    />
                    <ReasoningBlock
                        v-if="m.reasoning"
                        :reasoning="m.reasoning"
                        :streaming="
                            sending && streamingIndex === messages.indexOf(m)
                        "
                    />
                    <div
                        v-if="m.toolCalls?.length"
                        class="mb-2 flex flex-wrap gap-1.5"
                        data-tool-calls
                    >
                        <ToolCallChip
                            v-for="call in m.toolCalls"
                            :key="call.id"
                            :call="call"
                        />
                    </div>
                    <p
                        v-if="m.failed && !m.text"
                        class="text-[13px] text-destructive"
                        data-failed-turn
                    >
                        This reply failed.
                    </p>
                    <!-- v-html is fed by useMarkdown which sanitizes via DOMPurify. -->
                    <div
                        class="mm-markdown text-[14px] leading-relaxed"
                        v-html="renderMarkdown(m.text)"
                    />
                </div>
            </div>

            <div
                v-if="sending"
                class="flex items-center gap-2 text-sm text-fg-subtle"
            >
                <span class="mm-pulse">thinking…</span>
            </div>
            <StepLivenessBanner v-if="sending" :step="pendingStep" />
        </div>

        <!-- Composer -->
        <div
            :class="
                cn('border-t border-border bg-bg-elev', isSheet ? 'p-3' : 'p-5')
            "
        >
            <div
                v-if="error"
                class="mb-2 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
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
                    :disabled="
                        sending || pendingFiles.length >= MAX_ATTACHMENTS
                    "
                    @click="fileInput?.click()"
                >
                    <Paperclip class="size-3.5" />
                </Button>
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
                    @keydown="onKey"
                />
                <Button
                    type="button"
                    size="sm"
                    class="h-7 gap-1.5 text-xs"
                    :disabled="sending || !input.trim()"
                    @click="sendMessage()"
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
    </div>
</template>
