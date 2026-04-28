<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ArrowRight, Cpu, Sparkles } from 'lucide-vue-next';
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import AIChatController from '@/actions/App/Http/Controllers/AI/ChatController';
import { InitialsAvatar, Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';

interface WorkflowProposal {
    id: string;
    rationale: string;
    steps: Array<{ action: string; target: string; reason: string }>;
}

interface ChatMessage {
    role: 'user' | 'assistant';
    text: string;
    ts: number;
    workflow?: WorkflowProposal | null;
    workflowResolved?: 'approved' | 'declined' | null;
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Assistant', href: dashboard().url },
            { title: 'AI Assistant', href: AIChatController.index.url() },
        ],
    },
});

const messages = ref<ChatMessage[]>([]);
const input = ref('');
const sending = ref(false);
const error = ref<string | null>(null);
const conversationId = ref<string | null>(null);
const mode = ref<'advisory' | 'executive'>('executive');
const scrollRef = useTemplateRef<HTMLDivElement>('scroll');
const inputRef = useTemplateRef<HTMLTextAreaElement>('inputArea');

const TOOL_GROUPS: Array<{ name: string; count: number }> = [
    { name: 'Sonarr', count: 6 },
    { name: 'Radarr', count: 6 },
    { name: 'Emby', count: 5 },
    { name: 'Seerr', count: 8 },
    { name: 'Prowlarr', count: 2 },
    { name: 'System', count: 2 },
];

const totalTools = computed(() =>
    TOOL_GROUPS.reduce((acc, g) => acc + g.count, 0),
);

async function sendMessage(continuationPayload?: {
    workflow_id: string;
    workflow_action: 'approved' | 'declined';
    syntheticUserText: string;
}): Promise<void> {
    let bodyMessage: string;
    let extraBody: Record<string, unknown> = {};

    if (continuationPayload) {
        bodyMessage = continuationPayload.syntheticUserText;
        extraBody = {
            workflow_id: continuationPayload.workflow_id,
            workflow_action: continuationPayload.workflow_action,
        };
    } else {
        const text = input.value.trim();

        if (!text || sending.value) {
return;
}

        bodyMessage = text;
        messages.value.push({ role: 'user', text, ts: Date.now() });
        input.value = '';
    }

    sending.value = true;
    error.value = null;

    await nextTick();
    scrollRef.value?.scrollTo({
        top: scrollRef.value.scrollHeight,
        behavior: 'smooth',
    });

    try {
        const response = await fetch(AIChatController.send.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN':
                    (
                        document.querySelector(
                            'meta[name="csrf-token"]',
                        ) as HTMLMetaElement | null
                    )?.content ?? '',
            },
            body: JSON.stringify({
                message: bodyMessage,
                conversation_id: conversationId.value,
                mode: mode.value,
                ...extraBody,
            }),
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));

            throw new Error(
                data.error ?? `Request failed (${response.status})`,
            );
        }

        const data = (await response.json()) as {
            text: string;
            conversation_id: string | null;
            workflow: WorkflowProposal | null;
        };
        conversationId.value = data.conversation_id;
        messages.value.push({
            role: 'assistant',
            text: data.text,
            ts: Date.now(),
            workflow: data.workflow,
            workflowResolved: null,
        });
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Unknown error';
    } finally {
        sending.value = false;
        await nextTick();
        scrollRef.value?.scrollTo({
            top: scrollRef.value.scrollHeight,
            behavior: 'smooth',
        });
    }
}

function approveWorkflow(message: ChatMessage): void {
    if (!message.workflow || message.workflowResolved) {
return;
}

    message.workflowResolved = 'approved';
    sendMessage({
        workflow_id: message.workflow.id,
        workflow_action: 'approved',
        syntheticUserText: 'I approve the proposed workflow.',
    });
}

function declineWorkflow(message: ChatMessage): void {
    if (!message.workflow || message.workflowResolved) {
return;
}

    message.workflowResolved = 'declined';
    sendMessage({
        workflow_id: message.workflow.id,
        workflow_action: 'declined',
        syntheticUserText: 'I decline the proposed workflow.',
    });
}

function newConversation() {
    conversationId.value = null;
    messages.value = [];
    error.value = null;
    inputRef.value?.focus();
}

function onKey(event: KeyboardEvent) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
}
</script>

<template>
    <Head title="AI Assistant" />

    <div
        class="grid h-[calc(100vh-3.25rem)] min-h-0"
        style="grid-template-columns: 1fr 280px"
    >
        <!-- Main chat -->
        <div
            class="flex min-h-0 flex-col border-r border-border"
        >
            <!-- Header -->
            <div
                class="flex items-center justify-between border-b border-border px-6 py-3.5"
            >
                <div class="flex items-center gap-2.5">
                    <Sparkles class="size-4 text-accent" />
                    <span class="font-semibold">MediaAgent</span>
                    <span
                        class="font-mono-tabular text-[11px] text-muted-foreground"
                    >
                        claude-sonnet-4-6
                    </span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="text-xs text-muted-foreground">Mode</span>
                    <div
                        class="flex items-center gap-0.5 rounded-md border border-border bg-bg-elev p-0.5"
                    >
                        <button
                            v-for="m in (['advisory', 'executive'] as const)"
                            :key="m"
                            type="button"
                            :class="
                                cn(
                                    'inline-flex h-6 items-center rounded px-2 text-xs font-medium transition-colors',
                                    mode === m
                                        ? 'bg-accent text-accent-foreground'
                                        : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                                )
                            "
                            @click="mode = m"
                        >
                            {{ m }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Thread -->
            <div
                ref="scroll"
                class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-6 py-5"
            >
                <div
                    v-if="messages.length === 0"
                    class="flex h-full flex-col items-center justify-center gap-2 text-fg-subtle"
                >
                    <Sparkles class="size-6" />
                    <p class="max-w-[420px] text-center text-sm">
                        Ask about your library, request actions, or check
                        service health. Destructive tool calls queue an
                        ActionRequest in executive mode.
                    </p>
                </div>

                <div
                    v-for="m in messages"
                    :key="m.ts"
                    class="flex max-w-[720px] items-start gap-3"
                >
                    <InitialsAvatar
                        v-if="m.role === 'user'"
                        name="you"
                        :size="26"
                    />
                    <span
                        v-else
                        class="inline-flex size-[26px] items-center justify-center rounded-full border border-accent/28 bg-accent/18 text-accent"
                    >
                        <Sparkles class="size-3.5" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div
                            class="mb-1 text-[11.5px] text-muted-foreground"
                        >
                            {{ m.role === 'user' ? 'You' : 'MediaAgent' }}
                        </div>
                        <div
                            v-if="m.workflow"
                            class="mb-2 rounded-lg border border-border bg-bg-elev p-2.5"
                        >
                            <div
                                class="mb-1.5 flex items-center justify-between"
                            >
                                <span class="flex items-center gap-1.5">
                                    <Cpu class="size-3.5 text-fg-subtle" />
                                    <span class="text-[12px] font-medium">
                                        Proposed workflow
                                    </span>
                                    <Pill
                                        :variant="
                                            m.workflowResolved === 'approved'
                                                ? 'ok'
                                                : m.workflowResolved ===
                                                    'declined'
                                                  ? 'danger'
                                                  : 'warn'
                                        "
                                        class="text-[10px]"
                                    >
                                        {{
                                            m.workflowResolved ?? 'awaiting'
                                        }}
                                    </Pill>
                                </span>
                            </div>
                            <p class="mb-2 text-[12.5px] text-muted-foreground">
                                {{ m.workflow.rationale }}
                            </p>
                            <ol
                                class="mb-3 list-inside list-decimal space-y-1 text-[11.5px] text-muted-foreground"
                            >
                                <li
                                    v-for="(step, i) in m.workflow.steps"
                                    :key="i"
                                >
                                    <span
                                        class="font-mono-tabular text-foreground"
                                        >{{ step.action }}</span
                                    >
                                    on
                                    <span class="font-medium">{{
                                        step.target
                                    }}</span>
                                    — {{ step.reason }}
                                </li>
                            </ol>
                            <div v-if="!m.workflowResolved" class="flex gap-2">
                                <Button
                                    size="sm"
                                    class="h-7 text-xs"
                                    :disabled="sending"
                                    @click="approveWorkflow(m)"
                                >
                                    Approve
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    class="h-7 text-xs"
                                    :disabled="sending"
                                    @click="declineWorkflow(m)"
                                >
                                    Decline
                                </Button>
                            </div>
                            <p
                                v-else
                                class="text-[11.5px] text-muted-foreground"
                            >
                                {{
                                    m.workflowResolved === 'approved'
                                        ? 'Approved.'
                                        : 'Declined.'
                                }}
                            </p>
                        </div>
                        <div
                            class="text-[14px] leading-relaxed whitespace-pre-wrap"
                        >
                            {{ m.text }}
                        </div>
                    </div>
                </div>

                <div
                    v-if="sending"
                    class="flex items-center gap-2 text-sm text-fg-subtle"
                >
                    <span class="mm-pulse">thinking…</span>
                </div>
            </div>

            <!-- Composer -->
            <div
                class="border-t border-border bg-bg-elev p-5"
            >
                <div
                    v-if="error"
                    class="mb-2 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                >
                    {{ error }}
                </div>
                <div
                    class="flex items-end gap-2.5 rounded-xl border border-border bg-card p-2.5"
                >
                    <textarea
                        ref="inputArea"
                        v-model="input"
                        :placeholder="
                            mode === 'executive'
                                ? 'Ask MediaAgent · destructive calls require approval…'
                                : 'Ask MediaAgent · advisory mode (read-only)…'
                        "
                        rows="1"
                        class="max-h-[140px] min-h-6 flex-1 resize-none bg-transparent text-[14px] outline-none placeholder:text-fg-subtle"
                        @keydown="onKey"
                    />
                    <Button
                        type="button"
                        size="sm"
                        class="h-7 gap-1.5 text-xs"
                        :disabled="sending || !input.trim()"
                        @click="sendMessage()"
                    >
                        <ArrowRight class="size-3.5" />Send
                    </Button>
                </div>
                <div
                    class="mt-1.5 flex items-center gap-3 text-[11.5px] text-muted-foreground"
                >
                    <span class="flex items-center gap-1">
                        <kbd
                            class="font-mono-tabular rounded border border-border bg-card px-1 text-[10px]"
                            >↵</kbd
                        >
                        send
                    </span>
                    <span class="flex items-center gap-1">
                        <kbd
                            class="font-mono-tabular rounded border border-border bg-card px-1 text-[10px]"
                            >⇧+↵</kbd
                        >
                        newline
                    </span>
                    <span class="ml-auto">
                        {{
                            mode === 'executive'
                                ? 'Destructive tool calls queue an ActionRequest.'
                                : 'Destructive calls are short-circuited.'
                        }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <aside
            class="flex min-h-0 flex-col gap-4 overflow-y-auto p-5"
        >
            <div>
                <div
                    class="mb-2 text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                >
                    Conversation
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    class="h-8 w-full text-xs"
                    :disabled="sending || messages.length === 0"
                    @click="newConversation"
                >
                    + New conversation
                </Button>
                <div
                    v-if="conversationId"
                    class="font-mono-tabular mt-2 rounded-md border border-border bg-card px-2 py-1 text-[11px] text-fg-subtle"
                >
                    {{ conversationId.slice(0, 12) }}…
                </div>
            </div>

            <div>
                <div
                    class="mb-2 text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                >
                    Available tools · {{ totalTools }}
                </div>
                <div class="flex flex-col gap-1 text-xs text-muted-foreground">
                    <div
                        v-for="g in TOOL_GROUPS"
                        :key="g.name"
                        class="flex items-center justify-between rounded-md px-1.5 py-1"
                    >
                        <span>{{ g.name }}</span>
                        <span class="font-mono-tabular">{{ g.count }}</span>
                    </div>
                </div>
            </div>

            <div>
                <div
                    class="mb-2 text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                >
                    Cost · today
                </div>
                <div class="rounded-xl border border-border bg-card p-4">
                    <div class="text-[22px] font-semibold tabular-nums">
                        $0.00
                    </div>
                    <div class="text-xs text-muted-foreground">
                        wired in Phase 3 (AI usage rollup)
                    </div>
                </div>
            </div>
        </aside>
    </div>
</template>
