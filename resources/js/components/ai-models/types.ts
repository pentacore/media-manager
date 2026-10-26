import type { TierForm } from '@/components/ai/TierListEditor.vue';
import type { AiReasoningLevel, AiTask } from '@/typefinder';

export type ResolvedSummary = {
    provider: string;
    model: string;
    reasoning: AiReasoningLevel;
    reasoning_label: string;
    reasoning_hint: string | null;
    tier: {
        position: number;
        count: number;
        reason: string | null;
        via: string | null;
    } | null;
};

/** A saved event override as the page props bring it. */
export type EventOverrideRow = {
    task: AiTask;
    event_key: string;
    enabled: boolean;
    tiers: TierForm[];
    inherited: ResolvedSummary;
    resolved: ResolvedSummary;
    warning: string | null;
};

/** An event override in the form payload. */
export type EventOverrideForm = {
    task: AiTask;
    event_key: string;
    tiers: TierForm[];
};

/** The `data-*` attribute names one task's event-override block carries. */
export type EventOverrideHooks = {
    override: string;
    add: string;
    option: string;
    remove: string;
    resolved: string;
    warning: string;
};
