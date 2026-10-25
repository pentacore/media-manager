<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import ChatTemplateController from '@/actions/App/Http/Controllers/AI/ChatTemplateController';
import { InitialsAvatar } from '@/components/mm';
import { useAiChat } from '@/composables/useAiChat';
import { useMarkdown } from '@/composables/useMarkdown';
import { cn } from '@/lib/utils';
import { create as createBenchmarkCase } from '@/routes/admin/benchmarks/cases';
import ActionApprovalCard from './ActionApprovalCard.vue';
import AttachmentChips from './AttachmentChips.vue';
import ChoiceCard from './ChoiceCard.vue';
import ReasoningBlock from './ReasoningBlock.vue';
import ToolCallChip from './ToolCallChip.vue';
import TurnToolGroups from './TurnToolGroups.vue';
import type { ActionRequestCard, ChatMessage } from './types';
import WorkflowProposalCard from './WorkflowProposalCard.vue';

const props = defineProps<{
    message: ChatMessage;
    isSheet: boolean;
    /** This bubble is the reply currently streaming in. */
    streaming: boolean;
    /** A turn is in flight, so the workflow buttons stay disabled. */
    busy: boolean;
    /** This is the newest message and an assistant reply. */
    isLatestAssistant: boolean;
    /** Action request ids whose approve/reject is in flight. */
    reviewingActionIds: number[];
    /** Show the "Tools this turn" line under an assistant reply. */
    showTurnTools?: boolean;
}>();

const emit = defineEmits<{
    (e: 'approve'): void;
    (e: 'decline'): void;
    (e: 'choose', labels: string[]): void;
    (e: 'review', card: ActionRequestCard, action: 'approve' | 'reject'): void;
}>();

const { render: renderMarkdown } = useMarkdown();

const { closeChat } = useAiChat();

/** Server cap for a template body. */
const MAX_TEMPLATE_BODY = 4000;

/** The capture source the case form reads: the invocation when known, else the stored message. */
function benchmarkCaseQuery(
    message: ChatMessage,
): { from_invocation: string } | { from_chat_message: string } | null {
    if (message.invocation_id) {
        return { from_invocation: message.invocation_id };
    }

    return message.id ? { from_chat_message: message.id } : null;
}

/** Null when the reply cannot become a case: a workflow proposal is not a plain answer. */
const caseQuery = computed(() =>
    props.message.workflow ? null : benchmarkCaseQuery(props.message),
);
</script>

<template>
    <div
        :class="
            cn(
                'flex items-start gap-3',
                isSheet ? 'max-w-full' : 'max-w-[720px]',
            )
        "
    >
        <InitialsAvatar v-if="message.role === 'user'" name="you" :size="26" />
        <span
            v-else
            class="inline-flex size-[26px] items-center justify-center rounded-full border border-accent/28 bg-accent/18 text-accent"
        >
            <Sparkles class="size-3.5" />
        </span>

        <div class="min-w-0 flex-1">
            <div class="mb-1 text-[11.5px] text-muted-foreground">
                {{ message.role === 'user' ? 'You' : 'MediaAgent' }}
            </div>
            <WorkflowProposalCard
                v-if="message.workflow"
                :workflow="message.workflow"
                :resolved="message.workflowResolved"
                :disabled="busy"
                @approve="emit('approve')"
                @decline="emit('decline')"
            />
            <AttachmentChips
                v-if="message.attachments?.length"
                :items="
                    message.attachments.map((a, i) => ({
                        key: `${message.uid}-${i}`,
                        name: a.name,
                        mime: a.mime,
                        url: a.url || undefined,
                    }))
                "
                class="mb-1.5"
            />
            <ReasoningBlock
                v-if="message.reasoning"
                :reasoning="message.reasoning"
                :streaming="streaming"
            />
            <div
                v-if="message.toolCalls?.length"
                class="mb-2 flex flex-wrap gap-1.5"
                data-tool-calls
            >
                <ToolCallChip
                    v-for="call in message.toolCalls"
                    :key="call.id"
                    :call="call"
                />
            </div>
            <template
                v-for="card in message.cards ?? []"
                :key="`${card.type}-${card.id}`"
            >
                <ChoiceCard
                    v-if="card.type === 'choices'"
                    :card="card"
                    :interactive="isLatestAssistant && !busy && !message.chosen"
                    :chosen="message.chosen ?? null"
                    @choose="(labels) => emit('choose', labels)"
                />
                <ActionApprovalCard
                    v-else
                    :card="card"
                    :busy="reviewingActionIds.includes(card.id)"
                    @approve="emit('review', card, 'approve')"
                    @reject="emit('review', card, 'reject')"
                />
            </template>
            <p
                v-if="message.failed && !message.text"
                class="text-[13px] text-destructive"
                data-failed-turn
            >
                This reply failed.
            </p>
            <!-- v-html is fed by useMarkdown which sanitizes via DOMPurify. -->
            <div
                class="mm-markdown text-[14px] leading-relaxed"
                v-html="renderMarkdown(message.text)"
            />
            <Link
                v-if="message.role === 'user' && message.text"
                :href="
                    ChatTemplateController.create.url({
                        query: {
                            body: message.text.slice(0, MAX_TEMPLATE_BODY),
                        },
                    })
                "
                class="mt-1 inline-block text-[11.5px] text-muted-foreground hover:text-foreground"
                data-save-as-template
                @click="closeChat()"
                >Save as template</Link
            >
            <Link
                v-if="
                    message.role === 'assistant' &&
                    !message.failed &&
                    !streaming &&
                    message.text.trim() !== '' &&
                    caseQuery
                "
                :href="createBenchmarkCase({ query: caseQuery! })"
                class="mt-1 inline-block text-[11.5px] text-muted-foreground hover:text-foreground"
                data-chat-save-benchmark-case
                @click="closeChat()"
                >Save as benchmark case</Link
            >
            <p
                v-if="message.stopped"
                class="text-[12px] text-muted-foreground"
                data-stopped-turn
            >
                Stopped.
            </p>
            <p
                v-if="
                    message.role === 'assistant' && message.answered_by?.model
                "
                class="font-mono-tabular mt-1 text-[11px] text-fg-subtle"
                data-answered-by
            >
                <span>{{
                    [
                        message.answered_by.provider,
                        message.answered_by.model,
                        message.answered_by.reasoning_label,
                    ]
                        .filter(Boolean)
                        .join(' · ')
                }}</span>
                <span
                    v-if="(message.answered_by.tier?.position ?? 1) > 1"
                    :title="message.answered_by.tier?.reason ?? undefined"
                    data-answered-by-tier
                >
                    · tier {{ message.answered_by.tier?.position }}
                </span>
            </p>
            <TurnToolGroups
                v-if="message.role === 'assistant' && showTurnTools"
                :groups="message.tool_groups"
            />
        </div>
    </div>
</template>
