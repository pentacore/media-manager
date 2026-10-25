import type { ConversationMessage } from '@/composables/useAiChat';
import type { AiReasoningLevel } from '@/typefinder';

export type ChatMode = 'advisory' | 'executive';

/** A conversation's model/reasoning override; null fields use the chat default. */
export type ChatOverride = {
    provider: string | null;
    model: string | null;
    reasoning: AiReasoningLevel | null;
};

/** Which tier of the chat's tier list answered (absent without tiers). */
export type AnsweredTier = {
    position: number;
    count: number;
    reason: string | null;
    via?: string | null;
};

/** The model and reasoning level behind one assistant reply. */
export type AnsweredBy = {
    provider: string | null;
    model: string | null;
    reasoning_label: string | null;
    tier?: AnsweredTier | null;
};

/** What `GET ai/chat/model-options` returns for the model pickers. */
export type ModelOptions = {
    defaults: {
        provider: string;
        model: string;
        reasoning: AiReasoningLevel;
        reasoning_label: string;
        tier: AnsweredTier | null;
    };
    models: Record<string, string[]>;
    reasoningLevels: Array<{ label: string; value: AiReasoningLevel }>;
    modelCapabilities: Record<
        string,
        {
            supports_reasoning: boolean | null;
            levels: AiReasoningLevel[] | null;
        }
    >;
    reasoningProviders: string[];
};

/**
 * A routable tool group's key. Mirrors `App\Ai\Routing\ToolGroup`, which
 * sits outside `App\Enums`, so typefinder emits no enum for it.
 */
export type ToolGroupValue = string;

/** Why a tool group was loaded for a turn. */
export type ToolGroupSource =
    'pinned' | 'assistant' | 'carried_over' | 'picked';

/** One routed tool group a turn carried, with its label and why it loaded. */
export type TurnToolGroup = {
    group: ToolGroupValue;
    label: string;
    source: ToolGroupSource;
};

/** The groups a reply had: a list, `'all'` (routing off) or null (unknown). */
export type TurnToolGroups = TurnToolGroup[] | 'all' | null;

/** One entry of `GET ai/chat/tool-groups`. */
export type ToolGroupOption = {
    value: ToolGroupValue;
    label: string;
    available: boolean;
};

export interface WorkflowProposal {
    id: string;
    rationale: string;
    steps: Array<{ action: string; target: string; reason: string }>;
}

/** One answer a choices card offers. */
export interface ChoiceOption {
    label: string;
    description: string | null;
}

/**
 * A card under an assistant reply, derived from a tool result: clickable
 * choices (OfferChoicesTool) or an inline approval for a queued
 * ActionRequest. The shapes match `ChatCardExtractor` on the server.
 */
export type ChatCard =
    | {
          type: 'choices';
          /** The tool call id that offered the choices. */
          id: string;
          question: string;
          multiple: boolean;
          options: ChoiceOption[];
      }
    | {
          type: 'action_request';
          /** The ActionRequest id. */
          id: number;
          title: string | null;
          /** An ActionRequestStatus, or 'changed' once a review lost a race. */
          status: string;
          requires_approval: boolean;
      }
    | {
          type: 'tool_request';
          /** The tool call id of the assistant's RequestToolsTool call. */
          id: string;
          groups: Array<{ group: ToolGroupValue; label: string }>;
          reason: string;
      };

export type ChoicesCard = Extract<ChatCard, { type: 'choices' }>;

export type ToolRequestCard = Extract<ChatCard, { type: 'tool_request' }>;

export type ActionRequestCard = Extract<ChatCard, { type: 'action_request' }>;

export interface ChatMessage extends ConversationMessage {
    workflow?: WorkflowProposal | null;
    workflowResolved?: 'approved' | 'declined' | null;
    /** True when the user stopped the reply before it finished. */
    stopped?: boolean;
    /**
     * The option labels picked on this reply's choices card: set on a click,
     * or inferred from the next user message when the conversation loads.
     */
    chosen?: string[] | null;
    /**
     * Client-local monotonic key. Backend timestamps have second resolution
     * (a user message and its reply routinely collide) and Date.now() can
     * collide within a burst — colliding :key values make Vue's keyed diff
     * patch the wrong bubbles.
     */
    uid: number;
}

export interface PendingFile {
    key: string;
    file: File;
    previewUrl?: string;
}

/** What the composer hands the panel when the user sends a message. */
export interface ComposerSubmission {
    text: string;
    pendingFiles: PendingFile[];
    /** Tool groups to load for just this turn (the assistant's request). */
    loadToolGroups?: ToolGroupValue[];
}
