import { onMounted, ref } from 'vue';
import type { Ref } from 'vue';
import { toast } from 'vue-sonner';
import ChatToolGroupsController from '@/actions/App/Http/Controllers/AI/ChatToolGroupsController';
import ConversationController from '@/actions/App/Http/Controllers/AI/ConversationController';
import type {
    ToolGroupOption,
    ToolGroupValue,
    TurnToolGroup,
} from '@/components/ai/types';
import type { ConversationPage } from '@/composables/useAiChat';
import { useAiChat } from '@/composables/useAiChat';
import { jsonRequest } from '@/lib/http';

const TURN_LINES_KEY = 'mm.chat.showTurnTools';

type ToolGroupState = {
    pinned_tool_groups: ToolGroupValue[];
    carried_over_tool_groups: TurnToolGroup[];
};

export type UseChatToolGroupsReturn = {
    /** The options have been fetched; the strip renders only then. */
    loaded: Ref<boolean>;
    /** Routing is on; off means every turn carries every tool. */
    routingEnabled: Ref<boolean>;
    /** Every routable group and whether this install has its tools. */
    options: Ref<ToolGroupOption[]>;
    /** The groups pinned to the conversation (or to the new chat). */
    pinned: Ref<ToolGroupValue[]>;
    /** Groups the next turn would carry over from the last reply. */
    carriedOver: Ref<TurnToolGroup[]>;
    /** Show the per-reply "Tools this turn" line under replies. */
    showTurnLines: Ref<boolean>;
    /** Flips the per-reply line and remembers it in this browser. */
    toggleTurnLines: () => void;
    /** Pins a group: local for a new chat, saved for an existing one. */
    pin: (value: ToolGroupValue) => Promise<void>;
    /** Unpins a group the same way. */
    unpin: (value: ToolGroupValue) => Promise<void>;
    /** Takes the pins and carry-over from a loaded conversation (null: new). */
    loadFor: (page: ConversationPage | null) => void;
    /** Re-reads the pins and carry-over after a completed turn. */
    refresh: (conversationId: string) => Promise<void>;
};

/**
 * One chat panel's tool group state: the routable options, the conversation's
 * pins and what the next turn would carry over. A new chat keeps its pins
 * locally and sends them with its first turn; an existing conversation saves
 * each change and rolls it back when the save fails.
 */
export function useChatToolGroups(): UseChatToolGroupsReturn {
    const { activeConversationId } = useAiChat();

    const loaded = ref(false);
    const routingEnabled = ref(false);
    const options = ref<ToolGroupOption[]>([]);
    const pinned = ref<ToolGroupValue[]>([]);
    const carriedOver = ref<TurnToolGroup[]>([]);
    const showTurnLines = ref(true);

    /** Only the newest pin change may apply its response. */
    let pinRequest = 0;

    onMounted(async () => {
        // Read after mount: the server render cannot know the preference.
        try {
            showTurnLines.value =
                window.localStorage.getItem(TURN_LINES_KEY) !== 'false';
        } catch {
            // Storage is unavailable; the line stays on.
        }

        try {
            const data = await jsonRequest<{
                routing_enabled: boolean;
                groups: ToolGroupOption[];
            }>('GET', ChatToolGroupsController.url());
            routingEnabled.value = data.routing_enabled;
            options.value = data.groups;
            loaded.value = true;
        } catch {
            // No strip; turns still run.
        }
    });

    function toggleTurnLines(): void {
        showTurnLines.value = !showTurnLines.value;

        try {
            window.localStorage.setItem(
                TURN_LINES_KEY,
                String(showTurnLines.value),
            );
        } catch {
            // Not remembered; this view still changed.
        }
    }

    async function save(next: ToolGroupValue[]): Promise<void> {
        const conversationId = activeConversationId.value;
        const previous = pinned.value;
        pinned.value = next;

        if (conversationId === null) {
            return;
        }

        const request = ++pinRequest;

        try {
            const response = await jsonRequest<{
                pinned_tool_groups: ToolGroupValue[];
            }>(
                'PUT',
                ConversationController.updateToolGroups.url(conversationId),
                {
                    groups: next,
                },
            );

            if (
                request === pinRequest &&
                activeConversationId.value === conversationId
            ) {
                pinned.value = response.pinned_tool_groups;
            }
        } catch (e) {
            toast.error(
                e instanceof Error
                    ? e.message
                    : 'The tool groups could not be changed.',
            );

            if (
                request === pinRequest &&
                activeConversationId.value === conversationId
            ) {
                pinned.value = previous;
            }
        }
    }

    async function pin(value: ToolGroupValue): Promise<void> {
        if (pinned.value.includes(value)) {
            return;
        }

        await save([...pinned.value, value]);
    }

    async function unpin(value: ToolGroupValue): Promise<void> {
        await save(pinned.value.filter((group) => group !== value));
    }

    function loadFor(page: ConversationPage | null): void {
        pinRequest++;
        pinned.value = page?.pinned_tool_groups ?? [];
        carriedOver.value = page?.carried_over_tool_groups ?? [];
    }

    async function refresh(conversationId: string): Promise<void> {
        try {
            const state = await jsonRequest<ToolGroupState>(
                'GET',
                ConversationController.toolGroups.url(conversationId),
            );

            if (activeConversationId.value === conversationId) {
                pinned.value = state.pinned_tool_groups;
                carriedOver.value = state.carried_over_tool_groups;
            }
        } catch {
            // The strip keeps what it had.
        }
    }

    return {
        loaded,
        routingEnabled,
        options,
        pinned,
        carriedOver,
        showTurnLines,
        toggleTurnLines,
        pin,
        unpin,
        loadFor,
        refresh,
    };
}
