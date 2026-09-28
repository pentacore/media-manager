import type { ConversationMessage } from '@/composables/useAiChat';

export type ChatMode = 'advisory' | 'executive';

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
