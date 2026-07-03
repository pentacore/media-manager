import { computed, ref } from 'vue';
import ConversationController from '@/actions/App/Http/Controllers/AI/ConversationController';

export interface ConversationSummary {
    id: string;
    title: string;
    updated_at: string;
}

export interface ConversationMessage {
    role: 'user' | 'assistant';
    text: string;
    ts: number;
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

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

export async function jsonRequest<T>(
    method: string,
    url: string,
    body?: unknown,
): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (!response.ok) {
        const data = await response
            .json()
            .catch(() => ({}) as Record<string, unknown>);
        const message =
            typeof data.message === 'string'
                ? data.message
                : `Request failed (${response.status})`;

        throw new Error(message);
    }

    return (await response.json()) as T;
}

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

    const loadConversation = async (
        id: string,
    ): Promise<{
        id: string;
        title: string;
        updated_at: string;
        messages: ConversationMessage[];
    }> => {
        const data = await jsonRequest<{
            id: string;
            title: string;
            updated_at: string;
            messages: ConversationMessage[];
        }>('GET', ConversationController.show.url(id));
        activeConversationId.value = data.id;
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
