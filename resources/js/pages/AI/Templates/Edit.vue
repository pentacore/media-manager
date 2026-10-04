<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import AIChatController from '@/actions/App/Http/Controllers/AI/ChatController';
import ChatTemplateController from '@/actions/App/Http/Controllers/AI/ChatTemplateController';
import ChatTemplatePreviewController from '@/actions/App/Http/Controllers/AI/ChatTemplatePreviewController';
import {
    extractTokenNames,
    humanizeVariableName,
    TemplatePreview,
    VariableRow,
} from '@/components/chat-templates';
import type {
    ChatTemplate,
    ChatTemplateVariable,
    PreviewResponse,
    PreviewSegment,
    VariableTypeOption,
} from '@/components/chat-templates';
import InputError from '@/components/InputError.vue';
import { Toggle } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { jsonRequest } from '@/composables/useAiChat';
import { dashboard } from '@/routes';

const props = defineProps<{
    template: ChatTemplate | null;
    prefillBody: string;
    variableTypes: VariableTypeOption[];
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

/** Token examples live in script: a literal "{{" inside a template interpolation breaks Vue's parser. */
const SIMPLE_TOKEN = '{{name}}';
const LIBRARY_TOKEN = '{{anime:title,year,id}}';

const form = useForm({
    name: props.template?.name ?? '',
    body: props.template?.body ?? props.prefillBody,
    variables: [...(props.template?.variables ?? [])] as ChatTemplateVariable[],
    auto_send: props.template?.auto_send ?? false,
    pinned: props.template?.pinned ?? false,
});

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
            { body: form.body, variables: form.variables },
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
    () => [form.body, form.variables],
    () => {
        void refreshPreview();
    },
    { deep: true, immediate: true },
);

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
        <div>
            <h1 class="text-[20px] font-semibold">
                {{ template ? 'Edit template' : 'New template' }}
            </h1>
            <p class="mt-1 text-[12.5px] text-muted-foreground">
                Write the message once and mark the parts that change with
                <code>{{ SIMPLE_TOKEN }}</code
                >. Series and movie variables can pick parts:
                <code>{{ LIBRARY_TOKEN }}</code
                >.
            </p>
        </div>

        <label class="grid gap-1.5 text-[12.5px] font-medium">
            Name
            <Input v-model="form.name" class="h-9" data-template-name />
            <InputError :message="form.errors.name" />
        </label>

        <label class="grid gap-1.5 text-[12.5px] font-medium">
            Message
            <textarea
                v-model="form.body"
                rows="5"
                class="rounded-md border border-input bg-background px-3 py-2 font-mono text-[13px]"
                data-template-body
            />
            <span data-template-body-error>
                <InputError :message="form.errors.body" />
            </span>
        </label>

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
            <h2 class="text-[13px] font-semibold">Preview</h2>
            <TemplatePreview
                :segments="previewSegments"
                :errors="previewErrors"
                :failed="previewFailed"
            />
        </section>

        <div class="flex flex-wrap gap-6">
            <div class="grid gap-1 text-[12.5px]" data-template-auto-send>
                <span class="font-medium">Send right away</span>
                <Toggle
                    v-model="form.auto_send"
                    :label="
                        form.auto_send
                            ? 'Send is the default'
                            : 'Insert is the default'
                    "
                />
            </div>
            <div class="grid gap-1 text-[12.5px]" data-template-pinned>
                <span class="font-medium">Show on new chats</span>
                <Toggle v-model="form.pinned" />
            </div>
        </div>

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
