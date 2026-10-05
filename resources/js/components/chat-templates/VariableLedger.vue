<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { computed, nextTick, ref, useId, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import {
    buildToken,
    LIBRARY_PARTS,
    suggestVariableName,
    VARIABLE_NAME_PATTERN,
} from './tokens';
import type { LibraryPart } from './tokens';
import type {
    ChatTemplateVariable,
    ChatTemplateVariableKind,
    VariableTypeOption,
} from './types';

const props = defineProps<{
    variables: ChatTemplateVariable[];
    types: VariableTypeOption[];
}>();

const emit = defineEmits<{
    /** Put this token at the message cursor. */
    (e: 'insert', token: string): void;
    /** Create a variable of this type, then put its token at the cursor. */
    (e: 'add', name: string, type: ChatTemplateVariableKind): void;
}>();

const takenNames = computed(() => props.variables.map((v) => v.name));

function typeLabel(type: ChatTemplateVariableKind): string {
    return props.types.find((option) => option.value === type)?.label ?? type;
}

function isLibrary(type: ChatTemplateVariableKind): boolean {
    return type === 'series' || type === 'movie';
}

/** Chosen parts per variable name, in click order. */
const selectedParts = ref<Record<string, LibraryPart[]>>({});

function partsFor(name: string): LibraryPart[] {
    return selectedParts.value[name] ?? [];
}

function togglePart(name: string, part: LibraryPart): void {
    const current = partsFor(name);

    selectedParts.value = {
        ...selectedParts.value,
        [name]: current.includes(part)
            ? current.filter((p) => p !== part)
            : [...current, part],
    };
}

// Drop chip choices for variables that are gone or no longer a series or
// movie, so a later type switch can't put parts on a plain token.
watch(
    () => props.variables,
    (variables) => {
        const libraryNames = variables
            .filter((v) => isLibrary(v.type))
            .map((v) => v.name);
        const kept = Object.fromEntries(
            Object.entries(selectedParts.value).filter(([name]) =>
                libraryNames.includes(name),
            ),
        );

        if (
            Object.keys(kept).length !== Object.keys(selectedParts.value).length
        ) {
            selectedParts.value = kept;
        }
    },
);

/** The token Insert puts in the message; only series and movies take parts. */
function tokenFor(variable: ChatTemplateVariable): string {
    return buildToken(
        variable.name,
        isLibrary(variable.type) ? partsFor(variable.name) : [],
    );
}

/** Built in script: a literal "{{" inside a template interpolation breaks Vue's parser. */
function insertLabel(variable: ChatTemplateVariable): string {
    return `Insert ${tokenFor(variable)}`;
}

function insertVariable(variable: ChatTemplateVariable): void {
    emit('insert', tokenFor(variable));

    const reset = { ...selectedParts.value };
    delete reset[variable.name];
    selectedParts.value = reset;
}

const newType = ref<ChatTemplateVariableKind | null>(null);
const newName = ref('');
const newNameInput = ref<InstanceType<typeof Input> | null>(null);
const newNameErrorId = useId();

const newNameError = computed((): string | null => {
    if (!VARIABLE_NAME_PATTERN.test(newName.value)) {
        return 'Use up to 32 lowercase letters, digits or _, starting with a letter.';
    }

    if (takenNames.value.includes(newName.value)) {
        return 'This template already has a variable with that name.';
    }

    return null;
});

async function startAdding(type: ChatTemplateVariableKind): Promise<void> {
    newType.value = type;
    newName.value = suggestVariableName(type, takenNames.value);

    await nextTick();
    const el = newNameInput.value?.$el as HTMLInputElement | undefined;
    el?.focus();
    el?.select();
}

function cancelAdding(): void {
    newType.value = null;
}

function submitNew(): void {
    if (newType.value === null || newNameError.value !== null) {
        return;
    }

    emit('add', newName.value, newType.value);
    newType.value = null;
}
</script>

<template>
    <aside
        class="grid content-start gap-4 rounded-lg border border-border bg-card p-3 text-[12.5px]"
        data-variable-ledger
    >
        <section class="grid gap-2">
            <h2 class="text-[13px] font-semibold">This template's variables</h2>
            <p v-if="variables.length === 0" class="text-fg-subtle">
                None yet. Add one below, or type a placeholder in the message.
            </p>
            <div
                v-for="variable in variables"
                :key="variable.name"
                class="grid gap-1.5 rounded-md border border-border px-2 py-1.5"
                :data-ledger-variable="variable.name"
            >
                <div class="flex items-center gap-2">
                    <span class="min-w-0 flex-1 truncate font-mono text-[12px]">
                        {{ variable.name }}
                    </span>
                    <span class="shrink-0 text-[11.5px] text-fg-subtle">
                        {{ typeLabel(variable.type) }}
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="h-6 shrink-0 px-2 text-xs"
                        :aria-label="insertLabel(variable)"
                        :title="insertLabel(variable)"
                        data-ledger-insert
                        @click="insertVariable(variable)"
                        >Insert</Button
                    >
                </div>
                <div
                    v-if="isLibrary(variable.type)"
                    class="flex flex-wrap gap-1"
                    role="group"
                    :aria-label="`Parts for ${variable.name}`"
                >
                    <button
                        v-for="part in LIBRARY_PARTS"
                        :key="part"
                        type="button"
                        :aria-pressed="partsFor(variable.name).includes(part)"
                        :class="
                            cn(
                                'h-5 rounded border px-1.5 font-mono text-[11px] transition-colors',
                                partsFor(variable.name).includes(part)
                                    ? 'border-accent/30 bg-accent/12 text-accent'
                                    : 'border-border text-muted-foreground hover:bg-bg-hover',
                            )
                        "
                        :data-ledger-part="part"
                        @click="togglePart(variable.name, part)"
                    >
                        {{ part }}
                    </button>
                </div>
            </div>
        </section>

        <section class="grid gap-2">
            <h2 class="text-[13px] font-semibold">Add a variable</h2>
            <div class="flex flex-wrap gap-1">
                <Button
                    v-for="option in types"
                    :key="option.value"
                    type="button"
                    variant="outline"
                    size="sm"
                    :class="
                        cn(
                            'h-7 gap-1 px-2 text-xs',
                            newType === option.value && 'bg-bg-hover',
                        )
                    "
                    :data-ledger-add="option.value"
                    @click="startAdding(option.value)"
                >
                    <Plus class="size-3" />{{ option.label }}
                </Button>
            </div>
            <div v-if="newType !== null" class="grid gap-1">
                <span class="text-[12px] text-muted-foreground">
                    Name for the new {{ typeLabel(newType).toLowerCase() }}
                    variable
                </span>
                <div class="flex gap-1.5">
                    <Input
                        ref="newNameInput"
                        v-model="newName"
                        class="h-7 font-mono text-[12px]"
                        aria-label="New variable name"
                        :aria-invalid="newNameError !== null"
                        :aria-describedby="
                            newNameError !== null ? newNameErrorId : undefined
                        "
                        data-ledger-new-name
                        @keydown.enter.prevent="submitNew"
                        @keydown.esc.prevent="cancelAdding"
                    />
                    <Button
                        type="button"
                        size="sm"
                        class="h-7 px-2 text-xs"
                        :disabled="newNameError !== null"
                        data-ledger-new-insert
                        @click="submitNew"
                        >Insert</Button
                    >
                </div>
                <p
                    v-if="newNameError"
                    :id="newNameErrorId"
                    class="text-[12px] text-destructive"
                    data-ledger-new-error
                >
                    {{ newNameError }}
                </p>
            </div>
        </section>
    </aside>
</template>
