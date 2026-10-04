<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ChatTemplateRenderController from '@/actions/App/Http/Controllers/AI/ChatTemplateRenderController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useChatTemplates } from '@/composables/useChatTemplates';
import LibraryTitleCombobox from './LibraryTitleCombobox.vue';
import type {
    ChatTemplate,
    ChatTemplateVariable,
    LibraryHit,
    TemplateAction,
} from './types';

const props = defineProps<{
    template: ChatTemplate | null;
    /** A chat turn is in flight, so Send is unavailable. */
    sending: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'rendered', text: string, action: TemplateAction): void;
}>();

const { refreshTemplates } = useChatTemplates();

const values = ref<Record<string, string | number>>({});
const library = ref<Record<string, LibraryHit | null>>({});
const failure = ref<string | null>(null);

const http = useHttp<
    { values: Record<string, string | number | null> },
    { text: string }
>({ values: {} });

const primary = computed<TemplateAction>(() =>
    props.template?.auto_send ? 'send' : 'insert',
);

watch(
    () => props.template,
    (template) => {
        failure.value = null;
        http.clearErrors();
        values.value = Object.fromEntries(
            (template?.variables ?? []).map((v) => [v.name, v.default ?? '']),
        );
        library.value = Object.fromEntries(
            (template?.variables ?? [])
                .filter(isLibraryVariable)
                .map((v) => [v.name, null]),
        );
    },
    { immediate: true },
);

/** Series and movie variables are picked from the library, not typed. */
function isLibraryVariable(variable: ChatTemplateVariable): boolean {
    return variable.type === 'series' || variable.type === 'movie';
}

function fieldError(name: string): string | undefined {
    return (http.errors as Record<string, string | undefined>)[
        `values.${name}`
    ];
}

function payload(): Record<string, string | number | null> {
    return Object.fromEntries(
        (props.template?.variables ?? []).map((v) => {
            if (isLibraryVariable(v)) {
                return [v.name, library.value[v.name]?.id ?? null];
            }

            const raw = values.value[v.name] ?? '';

            return [
                v.name,
                v.type === 'number' && raw !== '' ? Number(raw) : raw,
            ];
        }),
    );
}

function submit(action: TemplateAction): void {
    const template = props.template;

    if (!template || http.processing || (action === 'send' && props.sending)) {
        return;
    }

    failure.value = null;
    http.values = payload();

    http.post(ChatTemplateRenderController.url(template.id), {
        onSuccess: (response) => {
            void refreshTemplates();
            emit('rendered', response.text, action);
        },
        onError: () => {
            failure.value =
                (http.errors as Record<string, string | undefined>).values ??
                null;
        },
        onHttpException: () => {
            failure.value = 'The template could not be filled in.';
        },
        onNetworkError: () => {
            failure.value = 'Could not reach the server. Try again.';
        },
    }).catch(() => {
        // Every failure is surfaced through the callbacks above.
    });
}
</script>

<template>
    <Dialog
        :open="template !== null"
        @update:open="
            (open: boolean) => {
                if (!open) {
                    emit('close');
                }
            }
        "
    >
        <DialogContent v-if="template" data-template-fill-dialog>
            <DialogHeader>
                <DialogTitle>{{ template.name }}</DialogTitle>
                <DialogDescription>Fill in the blanks.</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submit(primary)">
                <!-- A library picker holds several buttons, so it can't sit in a
                     <label>: clicking its text would activate the first one. -->
                <component
                    :is="isLibraryVariable(variable) ? 'div' : 'label'"
                    v-for="variable in template.variables"
                    :key="variable.name"
                    class="grid gap-1.5 text-[12.5px] font-medium"
                >
                    {{ variable.label }}
                    <LibraryTitleCombobox
                        v-if="
                            variable.type === 'series' ||
                            variable.type === 'movie'
                        "
                        v-model="library[variable.name]"
                        :type="variable.type"
                        :field-name="variable.name"
                    />
                    <select
                        v-else-if="variable.type === 'choice'"
                        v-model="values[variable.name]"
                        class="h-8 rounded-md border border-input bg-background px-2 text-sm font-normal"
                        :data-template-field="variable.name"
                    >
                        <option value="" disabled>Choose…</option>
                        <option
                            v-for="option in variable.options ?? []"
                            :key="option"
                            :value="option"
                        >
                            {{ option }}
                        </option>
                    </select>
                    <Input
                        v-else
                        v-model="values[variable.name]"
                        :type="variable.type === 'number' ? 'number' : 'text'"
                        class="h-8 text-sm font-normal"
                        :data-template-field="variable.name"
                    />
                    <InputError :message="fieldError(variable.name)" />
                </component>
                <p
                    v-if="failure"
                    class="text-[12.5px] text-destructive"
                    data-template-fill-error
                >
                    {{ failure }}
                </p>
                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        :variant="primary === 'insert' ? 'default' : 'outline'"
                        :disabled="http.processing"
                        data-template-insert
                        @click="submit('insert')"
                        >Insert</Button
                    >
                    <Button
                        type="button"
                        :variant="primary === 'send' ? 'default' : 'outline'"
                        :disabled="http.processing || sending"
                        data-template-send
                        @click="submit('send')"
                        >Send</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
