import { computed, ref } from 'vue';
import ConversationController from '@/actions/App/Http/Controllers/AI/ConversationController';
import type { AnsweredBy, ChatOverride } from '@/components/ai/types';
import { jsonRequest } from '@/lib/http';

export interface ConversationSummary {
    id: string;
    title: string;
    updated_at: string;
}

export interface ChatToolCall {
    id: string;
    name: string;
    status: 'running' | 'done' | 'failed';
    activity: string[];
}

export interface ChatAttachmentRef {
    id: number;
    name: string;
    mime: string;
    url: string;
}

export interface ConversationMessage {
    role: 'user' | 'assistant';
    text: string;
    ts: number;
    reasoning?: string;
    toolCalls?: ChatToolCall[];
    attachments?: ChatAttachmentRef[];
    failed?: boolean;
    /** The model behind an assistant reply; null when it is not known. */
    answered_by?: AnsweredBy | null;
}

export interface ConversationPage {
    id: string;
    title: string;
    updated_at: string;
    override: ChatOverride;
    messages: ConversationMessage[];
    next_cursor: string | null;
}

export interface AgentStep {
    conversationId: string;
    toolName: string;
    status: 'started' | 'finished';
    occurredAt: string;
}

const open = ref(false);
const activeConversationId = ref<string | null>(null);
const recent = ref<ConversationSummary[]>([]);
const recentLoaded = ref(false);
const recentLoading = ref(false);
const pendingStep = ref<AgentStep | null>(null);

let keyboardInitialized = false;

function ensureKeyboardShortcut(): void {
    if (keyboardInitialized) {
        return;
    }

    keyboardInitialized = true;

    if (typeof document === 'undefined') {
        return;
    }

    document.addEventListener(
        'keydown',
        (event: KeyboardEvent) => {
            if (
                (event.key === 'j' || event.key === 'J') &&
                (event.metaKey || event.ctrlKey)
            ) {
                event.preventDefault();
                event.stopPropagation();
                open.value = !open.value;
            }
        },
        { capture: true },
    );
}

export function useAiChat() {
    ensureKeyboardShortcut();

    const isOpen = computed({
        get: () => open.value,
        set: (next: boolean) => {
            open.value = next;
        },
    });

    const openChat = (conversationId?: string | null): void => {
        if (conversationId !== undefined) {
            activeConversationId.value = conversationId ?? null;
        }

        open.value = true;
    };

    const closeChat = (): void => {
        open.value = false;
    };

    const startNewConversation = (): void => {
        activeConversationId.value = null;
    };

    const setActiveConversation = (id: string | null): void => {
        activeConversationId.value = id;
    };

    const refreshRecent = async (force = false): Promise<void> => {
        if (recentLoading.value) {
            return;
        }

        if (recentLoaded.value && !force) {
            return;
        }

        recentLoading.value = true;

        try {
            const data = await jsonRequest<{
                data: ConversationSummary[];
            }>('GET', ConversationController.index.url());
            recent.value = data.data;
            recentLoaded.value = true;
        } finally {
            recentLoading.value = false;
        }
    };

    const upsertConversation = (summary: ConversationSummary): void => {
        const index = recent.value.findIndex((c) => c.id === summary.id);

        if (index === -1) {
            recent.value = [summary, ...recent.value].slice(0, 20);
        } else {
            const next = [...recent.value];
            next.splice(index, 1);
            recent.value = [summary, ...next];
        }
    };

    const removeConversation = (id: string): void => {
        recent.value = recent.value.filter((c) => c.id !== id);

        if (activeConversationId.value === id) {
            activeConversationId.value = null;
        }
    };

    const renameConversation = async (
        id: string,
        title: string,
    ): Promise<ConversationSummary> => {
        const data = await jsonRequest<ConversationSummary>(
            'PATCH',
            ConversationController.rename.url(id),
            { title },
        );
        upsertConversation(data);

        return data;
    };

    /**
     * Fetch one page of a conversation's history (30 messages, newest page
     * first, oldest to newest within the page). Pass the previous page's
     * `next_cursor` to fetch the page before it.
     */
    const loadConversation = async (
        id: string,
        cursor: string | null = null,
    ): Promise<ConversationPage> => {
        const data = await jsonRequest<ConversationPage>(
            'GET',
            ConversationController.show.url(id, {
                query: cursor ? { cursor } : {},
            }),
        );

        if (cursor) {
            return data;
        }

        // The caller makes the conversation active before loading it; a late
        // response must not switch back to a conversation the user left.
        upsertConversation({
            id: data.id,
            title: data.title,
            updated_at: data.updated_at,
        });

        return data;
    };

    const setPendingStep = (step: AgentStep | null): void => {
        pendingStep.value = step;
    };

    return {
        open: isOpen,
        activeConversationId,
        recent,
        recentLoading,
        pendingStep,
        openChat,
        closeChat,
        startNewConversation,
        setActiveConversation,
        refreshRecent,
        upsertConversation,
        removeConversation,
        renameConversation,
        loadConversation,
        setPendingStep,
    };
}
