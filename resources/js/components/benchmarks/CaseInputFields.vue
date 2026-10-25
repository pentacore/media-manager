<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import CaseAttachmentList from './CaseAttachmentList.vue';
import type {
    BenchmarkAttachmentEntry,
    BenchmarkConversationMode,
    BenchmarkHistoryTurnForm,
} from './types';

type InputState = {
    prompt: string;
    history: BenchmarkHistoryTurnForm[];
    mode: string;
    conversation: BenchmarkConversationMode;
    attachments: BenchmarkAttachmentEntry[];
    event_key: string;
    providers: string[];
};

const props = defineProps<{
    task: string;
    eventKeys: string[];
    /** Server validation errors keyed like `input.prompt`. */
    errors: Record<string, string | undefined>;
}>();

const input = defineModel<InputState>({ required: true });

/** Canonical Laravel AI provider ids the price updater can be asked to refresh. */
const PRICE_PROVIDERS = [
    'openai',
    'anthropic',
    'gemini',
    'xai',
    'deepseek',
    'mistral',
    'groq',
    'cohere',
    'openrouter',
];

/** A saved provider outside the standard list stays selectable. */
const providerOptions = computed(() => [
    ...PRICE_PROVIDERS,
    ...input.value.providers.filter(
        (provider) => !PRICE_PROVIDERS.includes(provider),
    ),
]);

function toggleProvider(provider: string, checked: boolean): void {
    input.value.providers = checked
        ? [...input.value.providers, provider]
        : input.value.providers.filter((current) => current !== provider);
}

function isIgnored(turn: BenchmarkHistoryTurnForm): boolean {
    return input.value.conversation === 'scripted' && turn.role === 'assistant';
}

function addHistoryTurn(): void {
    input.value.history = [
        ...input.value.history,
        { role: 'user', content: '', attachments: [] },
    ];
}

function removeHistoryTurn(index: number): void {
    input.value.history = input.value.history.filter((_, i) => i !== index);
}

/** The first server error under any of the attachment fields of a turn. */
function attachmentError(key: string): string | undefined {
    const fields = [
        `attachments.${key}`,
        `keep_attachment_ids.${key}`,
        `draft_attachment_ids.${key}`,
    ];
    const match = Object.entries(props.errors).find(
        ([field, message]) =>
            message &&
            fields.some(
                (name) => field === name || field.startsWith(`${name}.`),
            ),
    );

    return (
        match?.[1] ?? (key === 'final' ? props.errors.attachments : undefined)
    );
}

const selectClass =
    'h-9 rounded-md border border-input bg-background px-2 text-sm text-foreground';
const textareaClass =
    'rounded-md border border-input bg-background px-3 py-2 text-[13px] text-foreground';
</script>

<template>
    <section class="grid gap-3">
        <h2 class="text-[13px] font-semibold">Input</h2>

        <label class="grid gap-1.5 text-[12.5px] font-medium">
            Prompt
            <textarea
                v-model="input.prompt"
                rows="6"
                :class="textareaClass"
                data-benchmark-prompt
            />
            <InputError :message="errors['input.prompt']" />
        </label>

        <CaseAttachmentList
            v-if="task === 'chat'"
            v-model="input.attachments"
            turn-key="final"
            :error="attachmentError('final')"
        />

        <div
            v-if="task === 'chat'"
            class="grid gap-3"
            data-benchmark-chat-input
        >
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1.5 text-[12.5px] font-medium">
                    Mode
                    <select
                        v-model="input.mode"
                        :class="selectClass"
                        data-benchmark-mode
                    >
                        <option value="">Current setting</option>
                        <option value="executive">Executive</option>
                        <option value="advisory">Advisory</option>
                    </select>
                    <InputError :message="errors['input.mode']" />
                </label>
                <label class="grid gap-1.5 text-[12.5px] font-medium">
                    Replay mode
                    <select
                        v-model="input.conversation"
                        :class="selectClass"
                        data-benchmark-replay-mode
                    >
                        <option value="context">
                            Recorded replies as context
                        </option>
                        <option value="scripted">
                            Candidate answers every turn
                        </option>
                    </select>
                    <span
                        v-if="input.conversation === 'scripted'"
                        class="text-xs font-normal text-muted-foreground"
                        data-benchmark-scripted-hint
                    >
                        Each turn runs within one job; long conversations may
                        stop at the time budget.
                    </span>
                    <InputError :message="errors['input.conversation']" />
                </label>
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <span class="text-[12.5px] font-medium">Earlier turns</span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="h-7 gap-1 text-xs"
                        data-benchmark-history-add
                        @click="addHistoryTurn"
                    >
                        <Plus class="size-3.5" />Add turn
                    </Button>
                </div>
                <div
                    v-for="(turn, index) in input.history"
                    :key="index"
                    :class="
                        cn(
                            'grid gap-2 rounded-lg border border-border bg-card p-3 sm:grid-cols-[120px_1fr_auto]',
                            isIgnored(turn) && 'opacity-60',
                        )
                    "
                    data-benchmark-history-row
                >
                    <select
                        v-model="turn.role"
                        :class="selectClass"
                        data-benchmark-history-role
                    >
                        <option value="user">User</option>
                        <option value="assistant">Assistant</option>
                    </select>
                    <div class="grid gap-1">
                        <textarea
                            v-model="turn.content"
                            rows="2"
                            :readonly="isIgnored(turn)"
                            :class="textareaClass"
                            data-benchmark-history-content
                        />
                        <p
                            v-if="isIgnored(turn)"
                            class="text-[12px] text-muted-foreground"
                            data-benchmark-history-ignored
                        >
                            ignored in this mode
                        </p>
                        <InputError
                            :message="
                                errors[`input.history.${index}.content`] ??
                                errors[`input.history.${index}.role`]
                            "
                        />
                        <CaseAttachmentList
                            v-if="turn.role === 'user'"
                            v-model="turn.attachments"
                            :turn-key="String(index)"
                            :error="attachmentError(String(index))"
                        />
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-8 text-destructive"
                        title="Remove"
                        data-benchmark-history-remove
                        @click="removeHistoryTurn(index)"
                    >
                        <Trash2 class="size-3.5" />
                    </Button>
                </div>
            </div>
        </div>

        <label
            v-if="task === 'decision'"
            class="grid gap-1.5 text-[12.5px] font-medium sm:max-w-md"
        >
            Event
            <select
                v-model="input.event_key"
                :class="selectClass"
                data-benchmark-event-key
            >
                <option value="">Any event</option>
                <option v-for="key in eventKeys" :key="key" :value="key">
                    {{ key }}
                </option>
            </select>
            <InputError :message="errors['input.event_key']" />
        </label>

        <fieldset
            v-if="task === 'price_updater'"
            class="grid gap-1.5"
            data-benchmark-providers
        >
            <legend class="text-[12.5px] font-medium">Providers</legend>
            <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                <label
                    v-for="provider in providerOptions"
                    :key="provider"
                    class="flex items-center gap-1.5 text-[13px]"
                >
                    <input
                        type="checkbox"
                        :checked="input.providers.includes(provider)"
                        :data-benchmark-provider="provider"
                        @change="
                            toggleProvider(
                                provider,
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    />
                    {{ provider }}
                </label>
            </div>
            <InputError :message="errors['input.providers']" />
        </fieldset>
    </section>
</template>
