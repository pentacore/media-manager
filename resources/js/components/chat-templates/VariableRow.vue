<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import type {
    ChatTemplateVariable,
    ChatTemplateVariableKind,
    VariableTypeOption,
} from './types';

const props = defineProps<{
    modelValue: ChatTemplateVariable;
    types: VariableTypeOption[];
    /** Field errors keyed by the variable's own field (label, type, default, options, name). */
    errors: Record<string, string | undefined>;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: ChatTemplateVariable): void;
}>();

const isLibrary = computed(
    () =>
        props.modelValue.type === 'series' || props.modelValue.type === 'movie',
);

const optionsText = computed(() => (props.modelValue.options ?? []).join('\n'));

/** Built in script: a literal "{{" inside a template interpolation breaks Vue's parser. */
const tokenLabel = computed(() => `{{${props.modelValue.name}}}`);

function update(patch: Partial<ChatTemplateVariable>): void {
    emit('update:modelValue', { ...props.modelValue, ...patch });
}

function changeType(type: ChatTemplateVariableKind): void {
    const library = type === 'series' || type === 'movie';

    update({
        type,
        default: library ? null : props.modelValue.default,
        options: type === 'choice' ? (props.modelValue.options ?? []) : null,
    });
}

function changeOptions(text: string): void {
    update({ options: text.split('\n') });
}
</script>

<template>
    <div
        class="grid gap-3 rounded-lg border border-border bg-card p-3 sm:grid-cols-[140px_1fr_140px_1fr]"
        :data-variable-row="modelValue.name"
    >
        <div class="grid gap-1">
            <span class="font-mono-tabular text-[12px] text-muted-foreground">
                {{ tokenLabel }}
            </span>
            <InputError :message="errors.name" />
        </div>
        <label class="grid gap-1 text-[12px] text-muted-foreground">
            Label
            <Input
                :model-value="modelValue.label"
                class="h-8 text-sm"
                data-variable-label
                @update:model-value="update({ label: String($event) })"
            />
            <InputError :message="errors.label" />
        </label>
        <label class="grid gap-1 text-[12px] text-muted-foreground">
            Type
            <select
                class="h-8 rounded-md border border-input bg-background px-2 text-sm text-foreground"
                :value="modelValue.type"
                data-variable-type
                @change="
                    changeType(
                        ($event.target as HTMLSelectElement)
                            .value as ChatTemplateVariableKind,
                    )
                "
            >
                <option
                    v-for="option in types"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
            <InputError :message="errors.type" />
        </label>
        <div class="grid gap-1">
            <label
                v-if="modelValue.type === 'choice'"
                class="grid gap-1 text-[12px] text-muted-foreground"
            >
                Options (one per line)
                <textarea
                    :value="optionsText"
                    rows="3"
                    class="rounded-md border border-input bg-background px-2 py-1 text-sm text-foreground"
                    data-variable-options
                    @input="
                        changeOptions(
                            ($event.target as HTMLTextAreaElement).value,
                        )
                    "
                />
                <InputError :message="errors.options" />
            </label>
            <label
                v-if="!isLibrary"
                class="grid gap-1 text-[12px] text-muted-foreground"
            >
                Default (optional)
                <Input
                    :model-value="modelValue.default ?? ''"
                    class="h-8 text-sm"
                    data-variable-default
                    @update:model-value="
                        update({ default: String($event) || null })
                    "
                />
                <InputError :message="errors.default" />
            </label>
            <p v-else class="text-[12px] text-fg-subtle">
                Picked from your library each time. Add parts in the token:
                <code>title</code>, <code>year</code>, <code>id</code>.
            </p>
        </div>
    </div>
</template>
