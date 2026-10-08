<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import ChatTemplateController from '@/actions/App/Http/Controllers/AI/ChatTemplateController';
import { InitialsAvatar } from '@/components/mm';
import { useAiChat } from '@/composables/useAiChat';
import { useMarkdown } from '@/composables/useMarkdown';
import { cn } from '@/lib/utils';
import AttachmentChips from './AttachmentChips.vue';
import ReasoningBlock from './ReasoningBlock.vue';
import ToolCallChip from './ToolCallChip.vue';
import type { ChatMessage } from './types';
import WorkflowProposalCard from './WorkflowProposalCard.vue';

defineProps<{
    message: ChatMessage;
    isSheet: boolean;
    /** This bubble is the reply currently streaming in. */
    streaming: boolean;
    /** A turn is in flight, so the workflow buttons stay disabled. */
    busy: boolean;
}>();

const emit = defineEmits<{
    (e: 'approve'): void;
    (e: 'decline'): void;
}>();

const { render: renderMarkdown } = useMarkdown();

const { closeChat } = useAiChat();

/** Server cap for a template body. */
const MAX_TEMPLATE_BODY = 4000;
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
                {{
                    [
                        message.answered_by.provider,
                        message.answered_by.model,
                        message.answered_by.reasoning_label,
                    ]
                        .filter(Boolean)
                        .join(' · ')
                }}
            </p>
        </div>
    </div>
</template>
