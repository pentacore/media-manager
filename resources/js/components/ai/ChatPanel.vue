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
import { useChatTemplates } from '@/composables/useChatTemplates';
import { useChatToolGroups } from '@/composables/useChatToolGroups';
import { useChatTurns } from '@/composables/useChatTurns';
import { useWebSocket } from '@/composables/useWebSocket';
import type { ChannelLease } from '@/composables/useWebSocket';
import { jsonRequest } from '@/lib/http';
import { cn } from '@/lib/utils';
import ChatComposer from './ChatComposer.vue';
import ChatHeader from './ChatHeader.vue';
import ChatMessageBubble from './ChatMessageBubble.vue';
import ChatToolStrip from './ChatToolStrip.vue';
import StepLivenessBanner from './StepLivenessBanner.vue';
import type {
    AnsweredBy,
    ChatMode,
    ChatOverride,
    ModelOptions,
    WorkflowProposal,
} from './types';

const props = withDefaults(
    defineProps<{
        variant?: 'page' | 'sheet';
    }>(),
    { variant: 'page' },
);

const {
    activeConversationId,
    recent,
    pendingStep,
    setPendingStep,
    setActiveConversation,
    loadConversation,
    startNewConversation,
} = useAiChat();

const page = usePage();
const userId = computed(() => Number(page.props.auth.user?.id ?? 0));

const { acquirePrivateChannel } = useWebSocket();

const loading = ref(false);
/** Cursor for the page of history before the oldest loaded message. */
const olderCursor = ref<string | null>(null);
const loadingEarlier = ref(false);
let lastScrollTop = 0;
const mode = ref<ChatMode>('executive');

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

/** A workflow proposal is awaiting the user's approval or decline. */
const hasPendingWorkflow = computed(() =>
    messages.value.some((m) => m.workflow && !m.workflowResolved),
);

/**
 * The picker is locked while a conversation loads, a turn or an override
 * change is in flight, or a workflow proposal is pending.
 */
const modelLocked = computed(
    () =>
        !overrideLoaded.value ||
        loading.value ||
        sending.value ||
        savingOverride.value ||
        hasPendingWorkflow.value,
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
 * default otherwise. The optimistic fallback under a reply: the server's
 * answer (`answered_by`, or RUN_FINISHED's `answeredBy` when streamed) wins
 * once it arrives.
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
        tier: override.value.model ? null : options.defaults.tier,
    };
});

const toolGroups = useChatToolGroups();

const {
    messages,
    sending,
    error,
    streamingIndex,
    canStop,
    latestAssistant,
    reviewingActionIds,
    messageUid,
    inferChoices,
    sendUserMessage,
    stopStreaming,
    approveWorkflow,
    declineWorkflow,
    choose,
    answerToolRequest,
    reviewAction,
} = useChatTurns({
    mode,
    override,
    answeredBy: effectiveAnsweredBy,
    pinnedToolGroups: toolGroups.pinned,
    scrollToBottom,
});

/** A turn has settled: the server may have carried groups over or pinned. */
watch(sending, (isSending, wasSending) => {
    if (wasSending && !isSending && activeConversationId.value !== null) {
        void toolGroups.refresh(activeConversationId.value);
    }
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
 * Whether a template's model preset would fill the picker: only when it
 * starts a new, empty chat and no workflow proposal is pending. The fill
 * dialog shows its "Uses <model>" chip on the same condition.
 */
const templatePresetApplies = computed(
    () =>
        !hasPendingWorkflow.value &&
        activeConversationId.value === null &&
        messages.value.length === 0,
);

/**
 * Send rendered text, or put it in the composer while a turn is in flight or
 * a workflow proposal is pending. A template's model preset fills the picker
 * when it starts a new chat.
 */
function deliverTemplate(
    text: string,
    action: TemplateAction,
    template: ChatTemplate,
): void {
    fillTemplate.value = null;

    if (templatePresetApplies.value && template.preset !== null) {
        override.value = { ...template.preset };
    }

    if (action === 'send' && !sending.value && !hasPendingWorkflow.value) {
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
            toolGroups.loadFor(null);
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
});

async function scrollToBottom(): Promise<void> {
    await nextTick();
    scrollRef.value?.scrollTo({
        top: scrollRef.value.scrollHeight,
        behavior: 'smooth',
    });
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
        inferChoices();
        olderCursor.value = data.next_cursor;
        setOverride(data.override);
        toolGroups.loadFor(data);

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
        inferChoices();
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

function newConversation(): void {
    startNewConversation();
    messages.value = [];
    olderCursor.value = null;
    error.value = null;
    setOverride(emptyOverride());
    toolGroups.loadFor(null);
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
                :is-latest-assistant="latestAssistant === m"
                :show-turn-tools="toolGroups.showTurnLines.value"
                :reviewing-action-ids="reviewingActionIds"
                @approve="approveWorkflow(m)"
                @decline="declineWorkflow(m)"
                @choose="(labels) => choose(m, labels)"
                @answer-tool-request="
                    (card, load) => answerToolRequest(m, card, load)
                "
                @review="reviewAction"
            />

            <div
                v-if="sending"
                class="flex items-center gap-2 text-sm text-fg-subtle"
            >
                <span class="mm-pulse">thinking…</span>
            </div>
            <StepLivenessBanner v-if="sending" :step="pendingStep" />
        </div>

        <ChatToolStrip
            v-if="toolGroups.loaded.value"
            :routing-enabled="toolGroups.routingEnabled.value"
            :options="toolGroups.options.value"
            :pinned="toolGroups.pinned.value"
            :carried-over="toolGroups.carriedOver.value"
            :show-turn-lines="toolGroups.showTurnLines.value"
            :disabled="sending || loading"
            @pin="toolGroups.pin"
            @unpin="toolGroups.unpin"
            @toggle-lines="toolGroups.toggleTurnLines"
        />

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
            :sending="sending || hasPendingWorkflow"
            :preset-applies="templatePresetApplies"
            @close="fillTemplate = null"
            @rendered="onTemplateRendered"
        />
    </div>
</template>
