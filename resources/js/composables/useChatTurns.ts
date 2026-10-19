import type { ComputedRef, Ref } from 'vue';
import { computed, onUnmounted, ref } from 'vue';
import ActionRequestController from '@/actions/App/Http/Controllers/Actions/ActionRequestController';
import AIChatController from '@/actions/App/Http/Controllers/AI/ChatController';
import { inferChosenLabels } from '@/components/ai/chatCards';
import type {
    ActionRequestCard,
    AnsweredBy,
    ChatCard,
    ChatMessage,
    ChatMode,
    ChatOverride,
    ComposerSubmission,
    WorkflowProposal,
} from '@/components/ai/types';
import { useAiChat } from '@/composables/useAiChat';
import { ChatStreamError, useChatStream } from '@/composables/useChatStream';
import { submitBulk } from '@/lib/bulk';
import { csrfToken, jsonRequest } from '@/lib/http';

export type UseChatTurnsOptions = {
    /** The panel's mode toggle; read when a turn is sent. */
    mode: Ref<ChatMode>;
    /** The model picker; a new conversation sends it with its first turn. */
    override: Ref<ChatOverride>;
    /** Who answers the next turn, shown under a reply until the server says. */
    answeredBy: ComputedRef<AnsweredBy | null>;
    /** Scrolls the panel's thread to its end once the DOM has updated. */
    scrollToBottom: () => Promise<void>;
};

export type UseChatTurnsReturn = {
    /** The thread; the panel replaces it when it loads a conversation. */
    messages: Ref<ChatMessage[]>;
    /** A turn is in flight; a second send is ignored until it settles. */
    sending: Ref<boolean>;
    /** The last turn's (or the panel's load's) failure, shown in the composer. */
    error: Ref<string | null>;
    /** Index of the assistant message being streamed into, or null. */
    streamingIndex: Ref<number | null>;
    /** A streamed reply (not a blocking workflow continuation) is in flight. */
    canStop: ComputedRef<boolean>;
    /** The thread's last message when it is the assistant's, else null. */
    latestAssistant: ComputedRef<ChatMessage | null>;
    /** Ids of the action cards whose inline review is in flight. */
    reviewingActionIds: Ref<number[]>;
    /** Next `uid` (the thread's v-for key) for a message the panel loads. */
    messageUid: () => number;
    /** Re-marks a loaded choices card's pick from the reply that answered it. */
    inferChoices: () => void;
    /** Sends the composer's text and files as a streamed turn. */
    sendUserMessage: (submission: ComposerSubmission) => Promise<void>;
    /** Aborts the streamed turn in flight. */
    stopStreaming: () => void;
    /** Approves a proposed workflow as a blocking continuation turn. */
    approveWorkflow: (message: ChatMessage) => void;
    /** Declines a proposed workflow as a blocking continuation turn. */
    declineWorkflow: (message: ChatMessage) => void;
    /** Sends a choices card's picked labels as the user's answer. */
    choose: (message: ChatMessage, labels: string[]) => void;
    /** Approves or rejects a queued action card through the bulk review. */
    reviewAction: (
        card: ActionRequestCard,
        action: 'approve' | 'reject',
    ) => Promise<void>;
};

/**
 * One chat panel's turns: the thread's messages, sending a turn (streamed, or
 * blocking for a workflow continuation), stopping it, approving or declining
 * a proposed workflow, answering a choices card and reviewing a queued action
 * inline. The stream in flight is aborted when the panel unmounts (the
 * assistant sheet closes). The active conversation is `useAiChat()`'s, which
 * the panel shares, so a turn that creates a conversation makes it the
 * panel's active one.
 */
export function useChatTurns({
    mode,
    override,
    answeredBy: effectiveAnsweredBy,
    scrollToBottom,
}: UseChatTurnsOptions): UseChatTurnsReturn {
    let nextMessageUid = 0;

    function messageUid(): number {
        return nextMessageUid++;
    }

    const {
        activeConversationId,
        setPendingStep,
        setActiveConversation,
        upsertConversation,
        refreshRecent,
    } = useAiChat();

    const { streamChat } = useChatStream();

    const messages = ref<ChatMessage[]>([]);
    const sending = ref(false);
    const error = ref<string | null>(null);
    const streamingIndex = ref<number | null>(null);

    /** Aborts the in-flight streamed turn; null when no stream is running. */
    let streamAbortController: AbortController | null = null;

    /** A streamed reply (not a blocking workflow continuation) is in flight. */
    const canStop = computed(
        () => sending.value && streamingIndex.value !== null,
    );

    onUnmounted(() => {
        streamAbortController?.abort();
    });

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
            invocation_id?: string | null;
            cards?: ChatCard[];
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
            invocation_id: data.invocation_id ?? null,
            cards: data.cards ?? [],
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
                cards: [],
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
            onCard: (card) => {
                assistantMessage.cards = [
                    ...(assistantMessage.cards ?? []),
                    card,
                ];
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

        assistantMessage.answered_by = result.answeredBy ?? answeredBy;
        assistantMessage.invocation_id = result.invocationId ?? null;

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
                messages.value
                    .find((m) => m.role === 'user')
                    ?.text.slice(0, 60) ?? 'New chat',
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

    async function sendUserMessage(
        submission: ComposerSubmission,
    ): Promise<void> {
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

    /**
     * A loaded reply's choices card does not remember its pick: read it back
     * from the user message that answered it.
     */
    function inferChoices(): void {
        messages.value.forEach((message, index) => {
            const next = messages.value[index + 1];

            if (
                message.chosen === undefined &&
                next?.role === 'user' &&
                message.cards?.some((card) => card.type === 'choices')
            ) {
                message.chosen = inferChosenLabels(message.cards, next.text);
            }
        });
    }

    /** Send the picked option labels as the user's answer. */
    function choose(message: ChatMessage, labels: string[]): void {
        if (sending.value || labels.length === 0) {
            return;
        }

        message.chosen = labels;
        void sendUserMessage({ text: labels.join(', '), pendingFiles: [] });
    }

    /** Action request ids whose approve/reject request is in flight. */
    const reviewingActionIds = ref<number[]>([]);

    /**
     * Approve or reject a queued action through the Action Queue's bulk review.
     * A review that did not start (reviewed elsewhere first) marks the card
     * 'changed'; a refused request leaves it as it was.
     */
    async function reviewAction(
        card: ActionRequestCard,
        action: 'approve' | 'reject',
    ): Promise<void> {
        if (reviewingActionIds.value.includes(card.id)) {
            return;
        }

        reviewingActionIds.value = [...reviewingActionIds.value, card.id];

        const summary = await submitBulk(ActionRequestController.bulk.url(), {
            ids: [card.id],
            action,
        });

        reviewingActionIds.value = reviewingActionIds.value.filter(
            (id) => id !== card.id,
        );

        if (summary === null) {
            return;
        }

        if (summary.started !== 1) {
            card.status = 'changed';

            return;
        }

        // The pending-actions badge follows the ActionRequest broadcast's
        // pending_count; reloading `nav` would re-run whichever page is open.
        card.status = action === 'approve' ? 'approved' : 'rejected';
    }

    /** The newest message, when it is an assistant reply. */
    const latestAssistant = computed<ChatMessage | null>(() => {
        const last = messages.value[messages.value.length - 1];

        return last?.role === 'assistant' ? last : null;
    });

    return {
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
        reviewAction,
    };
}
