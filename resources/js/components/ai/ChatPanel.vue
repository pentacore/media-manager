<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import {
    computed,
    nextTick,
    onMounted,
    onUnmounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import { toast } from 'vue-sonner';
import AIChatController from '@/actions/App/Http/Controllers/AI/ChatController';
import ChatModelOptionsController from '@/actions/App/Http/Controllers/AI/ChatModelOptionsController';
import ConversationController from '@/actions/App/Http/Controllers/AI/ConversationController';
import {
    ChatTemplateChips,
    ChatTemplateFillDialog,
} from '@/components/chat-templates';
import type { ChatTemplate, TemplateAction } from '@/components/chat-templates';
import { useAiChat } from '@/composables/useAiChat';
import type { AgentStep } from '@/composables/useAiChat';
import { ChatStreamError, useChatStream } from '@/composables/useChatStream';
import { useChatTemplates } from '@/composables/useChatTemplates';
import { useWebSocket } from '@/composables/useWebSocket';
import type { ChannelLease } from '@/composables/useWebSocket';
import { csrfToken, jsonRequest } from '@/lib/http';
import { cn } from '@/lib/utils';
import ChatComposer from './ChatComposer.vue';
import ChatHeader from './ChatHeader.vue';
import ChatMessageBubble from './ChatMessageBubble.vue';
import StepLivenessBanner from './StepLivenessBanner.vue';
import type {
    AnsweredBy,
    ChatMessage,
    ChatMode,
    ChatOverride,
    ComposerSubmission,
    ModelOptions,
    WorkflowProposal,
} from './types';

const props = withDefaults(
    defineProps<{
        variant?: 'page' | 'sheet';
    }>(),
    { variant: 'page' },
);

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
    refreshRecent,
    startNewConversation,
} = useAiChat();

const page = usePage();
const userId = computed(() => Number(page.props.auth.user?.id ?? 0));

const { streamChat } = useChatStream();

const { acquirePrivateChannel } = useWebSocket();

const messages = ref<ChatMessage[]>([]);
const sending = ref(false);
const error = ref<string | null>(null);
const loading = ref(false);
/** Cursor for the page of history before the oldest loaded message. */
const olderCursor = ref<string | null>(null);
const loadingEarlier = ref(false);
let lastScrollTop = 0;
const mode = ref<ChatMode>('executive');
const streamingIndex = ref<number | null>(null);

function emptyOverride(): ChatOverride {
    return { provider: null, model: null, reasoning: null };
}

/** The conversation's model/reasoning override, as the picker shows it. */
const override = ref<ChatOverride>(emptyOverride());
/** The override the server last confirmed, restored when a change fails. */
let savedOverride: ChatOverride = emptyOverride();
const savingOverride = ref(false);
/**
 * Whether `override` holds the active conversation's saved override. A new
 * chat's is known (empty); an existing one's is unknown until its load
 * lands, and editing it then would overwrite fields the user never saw.
 */
const overrideLoaded = ref(activeConversationId.value === null);
/** Only the newest override change may apply its response. */
let overrideRequest = 0;
const modelOptions = ref<ModelOptions | null>(null);

/**
 * The picker is locked while a conversation loads or a turn or an override
 * change is in flight.
 */
const modelLocked = computed(
    () =>
        !overrideLoaded.value ||
        loading.value ||
        sending.value ||
        savingOverride.value,
);

function setOverride(value: ChatOverride): void {
    savedOverride = { ...value };
    override.value = { ...value };
    overrideLoaded.value = true;
}

/** Forget the override until the conversation being opened has loaded. */
function clearOverrideUntilLoaded(): void {
    savedOverride = emptyOverride();
    override.value = emptyOverride();
    overrideLoaded.value = false;
}

/**
 * Who answers the next turn: the override where it is set, the chat
 * default otherwise. Shown under streamed replies, whose frames carry no
 * model (blocking replies use the server's `answered_by`).
 */
const effectiveAnsweredBy = computed<AnsweredBy | null>(() => {
    const options = modelOptions.value;

    if (options === null) {
        return null;
    }

    const reasoning = override.value.reasoning;

    return {
        provider: override.value.provider ?? options.defaults.provider,
        model: override.value.model ?? options.defaults.model,
        reasoning_label:
            reasoning === null
                ? options.defaults.reasoning_label
                : (options.reasoningLevels.find(
                      (level) => level.value === reasoning,
                  )?.label ?? reasoning),
    };
});

onMounted(async () => {
    // The active conversation outlives this panel (the sheet unmounts on
    // close), so a remount reopens it to learn its messages and override.
    const id = activeConversationId.value;

    if (id !== null) {
        void openConversation(id);
    }

    try {
        modelOptions.value = await jsonRequest<ModelOptions>(
            'GET',
            ChatModelOptionsController.url(),
        );
    } catch {
        // The chip stays disabled; turns still run on the chat default.
    }
});

/**
 * A new conversation carries the override with its first turn; an existing
 * one saves it right away and falls back to the last saved value on failure.
 */
async function onOverrideChange(value: ChatOverride): Promise<void> {
    const conversationId = activeConversationId.value;

    if (conversationId === null || !overrideLoaded.value) {
        return;
    }

    const request = ++overrideRequest;
    savingOverride.value = true;

    try {
        const response = await jsonRequest<{ override: ChatOverride }>(
            'PATCH',
            ConversationController.updateModel.url(conversationId),
            value,
        );

        if (
            request === overrideRequest &&
            activeConversationId.value === conversationId
        ) {
            setOverride(response.override);
        }
    } catch (e) {
        toast.error(
            e instanceof Error ? e.message : 'The model could not be changed.',
        );

        if (
            request === overrideRequest &&
            activeConversationId.value === conversationId
        ) {
            override.value = { ...savedOverride };
        }
    } finally {
        if (request === overrideRequest) {
            savingOverride.value = false;
        }
    }
}

/** Aborts the in-flight streamed turn; null when no stream is running. */
let streamAbortController: AbortController | null = null;

/** A streamed reply (not a blocking workflow continuation) is in flight. */
const canStop = computed(() => sending.value && streamingIndex.value !== null);

const scrollRef = useTemplateRef<HTMLDivElement>('scroll');
const composerRef =
    useTemplateRef<InstanceType<typeof ChatComposer>>('composer');

const { renderWithoutValues } = useChatTemplates();

/** The template whose fill-in dialog is open. */
const fillTemplate = ref<ChatTemplate | null>(null);

/**
 * Templates with variables open the fill-in dialog; the rest render right
 * away and are sent (auto-send) or inserted into the composer.
 */
async function applyTemplate(template: ChatTemplate): Promise<void> {
    if (template.variables.length > 0) {
        fillTemplate.value = template;

        return;
    }

    try {
        const text = await renderWithoutValues(template);
        deliverTemplate(text, template.auto_send ? 'send' : 'insert', template);
    } catch (cause) {
        toast.error(
            cause instanceof Error
                ? cause.message
                : 'That template could not be used.',
        );
    }
}

/** The fill dialog rendered its template's text. */
function onTemplateRendered(text: string, action: TemplateAction): void {
    const template = fillTemplate.value;

    if (template === null) {
        return;
    }

    deliverTemplate(text, action, template);
}

/**
 * Send rendered text, or put it in the composer while a turn is in flight.
 * A template's model preset fills the picker when it starts a new chat.
 */
function deliverTemplate(
    text: string,
    action: TemplateAction,
    template: ChatTemplate,
): void {
    fillTemplate.value = null;

    if (
        template.preset !== null &&
        activeConversationId.value === null &&
        messages.value.length === 0
    ) {
        override.value = { ...template.preset };
    }

    if (action === 'send' && !sending.value) {
        void sendUserMessage({ text, pendingFiles: [] });

        return;
    }

    composerRef.value?.insertText(text);
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
            setOverride(emptyOverride());
        }

        // A first turn just created this conversation with the override
        // it carried, so that is now the saved value to fall back to.
        if (id && !prev) {
            savedOverride = { ...override.value };
        }
    },
    { immediate: true },
);

onUnmounted(() => {
    activeChannelLease?.release();
    activeChannelLease = null;
    streamAbortController?.abort();
});

async function scrollToBottom(): Promise<void> {
    await nextTick();
    scrollRef.value?.scrollTo({
        top: scrollRef.value.scrollHeight,
        behavior: 'smooth',
    });
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
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({
            message: bodyMessage,
            conversation_id: activeConversationId.value,
            mode: mode.value,
            ...(activeConversationId.value === null
                ? { override: override.value }
                : {}),
            ...extraBody,
        }),
    });

    if (!response.ok) {
        const data = await response
            .json()
            .catch(() => ({}) as Record<string, unknown>);
        // `message` is the human-readable explanation (budget cap, rate
        // limit); `error` is either a short code or a generic sentence.
        const errMsg =
            typeof data.message === 'string'
                ? data.message
                : typeof data.error === 'string'
                  ? data.error
                  : `Request failed (${response.status})`;

        throw new Error(errMsg);
    }

    const data = (await response.json()) as {
        text: string;
        conversation_id: string | null;
        workflow: WorkflowProposal | null;
        answered_by?: AnsweredBy | null;
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
        answered_by: data.answered_by ?? null,
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
    // The picker is locked for the turn, so this is who answers it.
    const answeredBy = effectiveAnsweredBy.value;

    streamAbortController = new AbortController();

    const result = await streamChat({
        message: bodyMessage,
        conversationId: knownConversationId,
        mode: mode.value,
        attachments: files,
        override: override.value,
        signal: streamAbortController.signal,
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
    })
        .catch((e: unknown) => {
            // A failed turn the server stored still belongs to a conversation
            // the user can continue — adopt it so a retry doesn't start over.
            if (e instanceof ChatStreamError && e.conversationId) {
                adoptConversation(e.conversationId, knownConversationId);
            }

            throw e;
        })
        .finally(() => {
            streamAbortController = null;
        });

    if (result.stopped) {
        assistantMessage.stopped = true;

        // A stopped brand-new chat has a row only if a step completed, so
        // adopting the minted id could 404 the next turn; refresh the picker
        // instead and let the user open it from there.
        if (knownConversationId) {
            rememberConversation(knownConversationId);
        } else {
            void refreshRecent(true);
        }

        return;
    }

    assistantMessage.answered_by = answeredBy;

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

function stopStreaming(): void {
    streamAbortController?.abort();
}

async function pickConversation(id: string): Promise<void> {
    // Re-picking the open conversation retries a load that failed.
    if (id === activeConversationId.value && overrideLoaded.value) {
        return;
    }

    setActiveConversation(id);
    await openConversation(id);
}

/** Bumped per conversation load, so only the newest one applies its result. */
let conversationLoad = 0;

/**
 * Load the (already active) conversation's newest messages and override.
 * A load the user has since moved away from is dropped; a failed one keeps
 * the model picker locked rather than editable over an unknown override.
 */
async function openConversation(id: string): Promise<void> {
    const load = ++conversationLoad;
    const isCurrent = (): boolean =>
        load === conversationLoad && activeConversationId.value === id;

    messages.value = [];
    olderCursor.value = null;
    clearOverrideUntilLoaded();
    error.value = null;
    loading.value = true;

    try {
        const data = await loadConversation(id);

        if (!isCurrent()) {
            return;
        }

        messages.value = data.messages.map((m) => ({
            ...m,
            uid: messageUid(),
        }));
        olderCursor.value = data.next_cursor;
        setOverride(data.override);

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

        if (pending.workflow && isCurrent()) {
            const lastAssistant = [...messages.value]
                .reverse()
                .find((m) => m.role === 'assistant');

            if (lastAssistant) {
                lastAssistant.workflow = pending.workflow;
                lastAssistant.workflowResolved = null;
            }
        }
    } catch (e) {
        if (isCurrent()) {
            error.value = e instanceof Error ? e.message : 'Failed to load.';
        }
    } finally {
        if (load === conversationLoad) {
            loading.value = false;
            await scrollToBottom();
        }
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

/**
 * Run one turn: flag it in flight, send it, and surface a failure on the
 * empty assistant bubble it left behind.
 */
async function runTurn(send: () => Promise<void>): Promise<void> {
    sending.value = true;
    error.value = null;
    setPendingStep(null);

    await scrollToBottom();

    try {
        await send();
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

async function sendUserMessage(submission: ComposerSubmission): Promise<void> {
    if (sending.value) {
        return;
    }

    // The object URLs stay alive so the sent bubble keeps its previews.
    messages.value.push({
        role: 'user',
        text: submission.text,
        ts: Date.now(),
        uid: messageUid(),
        attachments: submission.pendingFiles.map((p) => ({
            id: 0,
            name: p.file.name,
            mime: p.file.type,
            url: p.previewUrl ?? '',
        })),
    });

    await runTurn(() =>
        sendStreamingTurn(
            submission.text,
            submission.pendingFiles.map((p) => p.file),
        ),
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

    const workflowId = message.workflow.id;

    await runTurn(() =>
        sendBlockingTurn(syntheticUserText, {
            workflow_id: workflowId,
            workflow_action: action,
        }),
    );

    if (error.value !== null) {
        message.workflowResolved = null;
    }
}

function newConversation(): void {
    startNewConversation();
    messages.value = [];
    olderCursor.value = null;
    error.value = null;
    setOverride(emptyOverride());
    composerRef.value?.focus();
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
        <ChatHeader
            v-model:mode="mode"
            v-model:override="override"
            :title="activeTitle"
            :is-sheet="isSheet"
            :model-options="modelOptions"
            :model-locked="modelLocked"
            @select="pickConversation"
            @new="newConversation"
            @override-change="onOverrideChange"
        />

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
                <ChatTemplateChips class="mt-3" @pick="applyTemplate" />
            </div>

            <ChatMessageBubble
                v-for="(m, index) in messages"
                :key="m.uid"
                :message="m"
                :is-sheet="isSheet"
                :streaming="sending && streamingIndex === index"
                :busy="sending"
                @approve="approveWorkflow(m)"
                @decline="declineWorkflow(m)"
            />

            <div
                v-if="sending"
                class="flex items-center gap-2 text-sm text-fg-subtle"
            >
                <span class="mm-pulse">thinking…</span>
            </div>
            <StepLivenessBanner v-if="sending" :step="pendingStep" />
        </div>

        <ChatComposer
            ref="composer"
            :mode="mode"
            :is-sheet="isSheet"
            :sending="sending"
            :can-stop="canStop"
            :error="error"
            @send="sendUserMessage"
            @stop="stopStreaming"
            @template="applyTemplate"
        />

        <ChatTemplateFillDialog
            :template="fillTemplate"
            :sending="sending"
            @close="fillTemplate = null"
            @rendered="onTemplateRendered"
        />
    </div>
</template>
