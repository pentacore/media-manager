<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { nextTick, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AIChatController from '@/actions/App/Http/Controllers/AI/ChatController';
import ChatModelOptionsController from '@/actions/App/Http/Controllers/AI/ChatModelOptionsController';
import ChatTemplateController from '@/actions/App/Http/Controllers/AI/ChatTemplateController';
import ChatTemplatePreviewController from '@/actions/App/Http/Controllers/AI/ChatTemplatePreviewController';
import ModelSelect from '@/components/ai/ModelSelect.vue';
import ReasoningSelect from '@/components/ai/ReasoningSelect.vue';
import type { ModelOptions } from '@/components/ai/types';
import {
    buildToken,
    extractTokenNames,
    humanizeVariableName,
    insertAtSelection,
    TemplateHelp,
    TemplatePreview,
    VariableLedger,
    VariableRow,
} from '@/components/chat-templates';
import type {
    ChatTemplate,
    ChatTemplateVariable,
    ChatTemplateVariableKind,
    PreviewModeOption,
    PreviewResponse,
    PreviewSegment,
    VariableTypeOption,
} from '@/components/chat-templates';
import InputError from '@/components/InputError.vue';
import { Field, SegmentedControl, Toggle } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { jsonRequest } from '@/lib/http';
import { dashboard } from '@/routes';
import type { AiReasoningLevel, ChatTemplatePreviewMode } from '@/typefinder';

const props = defineProps<{
    template: ChatTemplate | null;
    prefillBody: string;
    variableTypes: VariableTypeOption[];
    previewModes: PreviewModeOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Assistant', href: dashboard().url },
            { title: 'AI Assistant', href: AIChatController.index.url() },
            { title: 'Templates', href: ChatTemplateController.index.url() },
        ],
    },
});

const form = useForm({
    name: props.template?.name ?? '',
    body: props.template?.body ?? props.prefillBody,
    variables: [...(props.template?.variables ?? [])] as ChatTemplateVariable[],
    auto_send: props.template?.auto_send ?? false,
    pinned: props.template?.pinned ?? false,
    preset: {
        provider: props.template?.preset?.provider ?? null,
        model: props.template?.preset?.model ?? null,
        reasoning: props.template?.preset?.reasoning ?? null,
    } as {
        provider: string | null;
        model: string | null;
        reasoning: AiReasoningLevel | null;
    },
});

const modelOptions = ref<ModelOptions | null>(null);

/** ModelSelect speaks '' for "inherit"; the form stores null. */
function setPresetProvider(value: string): void {
    form.preset.provider = value === '' ? null : value;
}

function setPresetModel(value: string): void {
    form.preset.model = value === '' ? null : value;
}

/** Settings for names whose token was removed, restored if it comes back. */
const remembered = new Map<string, ChatTemplateVariable>();

function defaultVariable(name: string): ChatTemplateVariable {
    return {
        name,
        label: humanizeVariableName(name),
        type: 'text',
        default: null,
        options: null,
    };
}

watch(
    () => form.body,
    (body) => {
        for (const variable of form.variables) {
            remembered.set(variable.name, variable);
        }

        form.variables = extractTokenNames(body).map(
            (name) =>
                form.variables.find((v) => v.name === name) ??
                remembered.get(name) ??
                defaultVariable(name),
        );
    },
    { immediate: true },
);

const bodyInput = ref<HTMLTextAreaElement | null>(null);

/** Until the message box has had focus its selection means nothing, so inserts append. */
const bodyWasFocused = ref(false);

async function insertIntoBody(token: string): Promise<void> {
    const el = bodyInput.value;
    const atEnd = form.body.length;
    const { text, caret } = insertAtSelection(
        form.body,
        bodyWasFocused.value && el ? el.selectionStart : atEnd,
        bodyWasFocused.value && el ? el.selectionEnd : atEnd,
        token,
    );

    form.body = text;

    await nextTick();
    el?.focus();
    el?.setSelectionRange(caret, caret);
}

/** Seeds the settings the body watcher picks up, so the new row starts with the chosen type. */
function addVariable(name: string, type: ChatTemplateVariableKind): void {
    remembered.set(name, {
        name,
        label: humanizeVariableName(name),
        type,
        default: null,
        options: type === 'choice' ? [] : null,
    });

    void insertIntoBody(buildToken(name));
}

function rowErrors(index: number): Record<string, string | undefined> {
    const errors = form.errors as Record<string, string | undefined>;
    const prefix = `variables.${index}.`;

    return Object.fromEntries(
        Object.entries(errors)
            .filter(([key]) => key.startsWith(prefix))
            .map(([key, message]) => [key.slice(prefix.length), message]),
    );
}

function updateVariable(index: number, variable: ChatTemplateVariable): void {
    form.variables = form.variables.map((current, i) =>
        i === index ? variable : current,
    );
}

const PREVIEW_MODE_KEY = 'mm.chat-templates.preview-mode';
const previewMode = ref<ChatTemplatePreviewMode>('example');

function setPreviewMode(mode: ChatTemplatePreviewMode): void {
    previewMode.value = mode;

    try {
        localStorage.setItem(PREVIEW_MODE_KEY, mode);
    } catch {
        // localStorage full or disabled — the choice lasts for this visit.
    }
}

const previewSegments = ref<PreviewSegment[]>([]);
const previewErrors = ref<Record<string, string[]>>({});
const previewFailed = ref(false);
let previewRequest = 0;

const refreshPreview = useDebounceFn(async (): Promise<void> => {
    const request = ++previewRequest;

    try {
        const response = await jsonRequest<PreviewResponse>(
            'POST',
            ChatTemplatePreviewController.url(),
            {
                body: form.body,
                variables: form.variables,
                mode: previewMode.value,
            },
        );

        if (request !== previewRequest) {
            return;
        }

        previewSegments.value = response.segments;
        previewErrors.value = response.errors;
        previewFailed.value = false;
    } catch {
        if (request === previewRequest) {
            previewFailed.value = true;
        }
    }
}, 300);

watch(
    () => [form.body, form.variables, previewMode.value],
    () => {
        void refreshPreview();
    },
    { deep: true },
);

// The first preview waits for the browser: during SSR setup the fetch has no
// server to reach and would only fail. The stored mode is read here too, so
// the server-rendered markup matches the first client render.
onMounted(() => {
    try {
        const stored = localStorage.getItem(PREVIEW_MODE_KEY);

        if (props.previewModes.some((mode) => mode.value === stored)) {
            previewMode.value = stored as ChatTemplatePreviewMode;
        }
    } catch {
        // localStorage disabled — keep example values.
    }

    void refreshPreview();

    void jsonRequest<ModelOptions>('GET', ChatModelOptionsController.url())
        .then((options) => {
            modelOptions.value = options;
        })
        .catch(() => {
            toast.error('Could not load the model options.');
        });
});

function save(): void {
    if (props.template) {
        form.patch(ChatTemplateController.update.url(props.template.id));

        return;
    }

    form.post(ChatTemplateController.store.url());
}
</script>

<template>
    <Head :title="template ? 'Edit template' : 'New template'" />

    <form class="max-w-[900px] space-y-6 p-6" @submit.prevent="save">
        <div class="grid gap-3">
            <h1 class="text-[20px] font-semibold">
                {{ template ? 'Edit template' : 'New template' }}
            </h1>
            <TemplateHelp :default-open="template === null" />
        </div>

        <label class="grid gap-1.5 text-[12.5px] font-medium">
            Name
            <Input v-model="form.name" class="h-9" data-template-name />
            <InputError :message="form.errors.name" />
        </label>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_280px]">
            <label class="grid content-start gap-1.5 text-[12.5px] font-medium">
                Message
                <textarea
                    ref="bodyInput"
                    v-model="form.body"
                    rows="8"
                    class="rounded-md border border-input bg-background px-3 py-2 font-mono text-[13px]"
                    data-template-body
                    @focus="bodyWasFocused = true"
                />
                <span data-template-body-error>
                    <InputError :message="form.errors.body" />
                </span>
            </label>
            <VariableLedger
                :variables="form.variables"
                :types="variableTypes"
                @insert="insertIntoBody"
                @add="addVariable"
            />
        </div>

        <section v-if="form.variables.length > 0" class="grid gap-2">
            <h2 class="text-[13px] font-semibold">Variables</h2>
            <VariableRow
                v-for="(variable, index) in form.variables"
                :key="variable.name"
                :model-value="variable"
                :types="variableTypes"
                :errors="rowErrors(index)"
                @update:model-value="updateVariable(index, $event)"
            />
        </section>

        <section class="grid gap-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-[13px] font-semibold">Preview</h2>
                <SegmentedControl
                    :options="previewModes"
                    :model-value="previewMode"
                    option-attribute="preview-mode"
                    aria-label="Preview shows"
                    @update:model-value="setPreviewMode"
                />
            </div>
            <TemplatePreview
                :segments="previewSegments"
                :errors="previewErrors"
                :failed="previewFailed"
            />
        </section>

        <div class="grid gap-6 sm:grid-cols-2">
            <Field
                label="Send right away"
                hint="When you pick this template, send it immediately instead of putting it in the message box."
                data-template-auto-send
            >
                <Toggle v-model="form.auto_send" aria-label="Send right away" />
            </Field>
            <Field
                label="Show on new chats"
                hint="Show as a shortcut on an empty chat."
                data-template-pinned
            >
                <Toggle v-model="form.pinned" aria-label="Show on new chats" />
            </Field>
        </div>

        <Field
            v-if="modelOptions"
            label="Model preset"
            hint="Applied when this template starts a new conversation. Leave both on Conversation default to use the chat defaults."
            data-template-preset
        >
            <div class="grid gap-2 sm:max-w-md">
                <ModelSelect
                    :models="modelOptions.models"
                    inherit-label="Conversation default"
                    :provider="form.preset.provider ?? ''"
                    :model="form.preset.model ?? ''"
                    @update:provider="setPresetProvider"
                    @update:model="setPresetModel"
                />
                <ReasoningSelect
                    v-model="form.preset.reasoning"
                    :levels="modelOptions.reasoningLevels"
                    inherit-label="Conversation default"
                />
                <InputError :message="form.errors['preset.model']" />
                <InputError :message="form.errors['preset.provider']" />
            </div>
        </Field>

        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing" data-template-save
                >Save template</Button
            >
            <Button variant="ghost" as-child>
                <Link :href="ChatTemplateController.index.url()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
