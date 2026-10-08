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

export interface WorkflowProposal {
    id: string;
    rationale: string;
    steps: Array<{ action: string; target: string; reason: string }>;
}

export interface ChatMessage extends ConversationMessage {
    workflow?: WorkflowProposal | null;
    workflowResolved?: 'approved' | 'declined' | null;
    /** True when the user stopped the reply before it finished. */
    stopped?: boolean;
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
}
