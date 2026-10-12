<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { BenchmarkHistoryTurn } from './types';

type InputState = {
    prompt: string;
    history: BenchmarkHistoryTurn[];
    mode: string;
    event_key: string;
    providers: string[];
};

defineProps<{
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

function addHistoryTurn(): void {
    input.value.history = [
        ...input.value.history,
        { role: 'user', content: '' },
    ];
}

function removeHistoryTurn(index: number): void {
    input.value.history = input.value.history.filter((_, i) => i !== index);
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

        <div
            v-if="task === 'chat'"
            class="grid gap-3"
            data-benchmark-chat-input
        >
            <label class="grid gap-1.5 text-[12.5px] font-medium sm:max-w-xs">
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
                    class="grid gap-2 rounded-lg border border-border bg-card p-3 sm:grid-cols-[120px_1fr_auto]"
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
                            :class="textareaClass"
                            data-benchmark-history-content
                        />
                        <InputError
                            :message="
                                errors[`input.history.${index}.content`] ??
                                errors[`input.history.${index}.role`]
                            "
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
