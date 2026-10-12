<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { formatJsonObject, parseJsonObject } from './jsonObject';
import type { BenchmarkExpectedAction } from './types';

type Row = {
    key: number;
    tool: string;
    argumentsText: string;
    argumentsError: string | null;
    lastValid: BenchmarkExpectedAction['arguments'];
};

const props = defineProps<{
    modelValue: BenchmarkExpectedAction[];
    /** Tool names an expected action can name. */
    writeTools: string[];
    /** Server validation errors keyed like `scoring.expected.0.tool`. */
    errors?: Record<string, string | undefined>;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: BenchmarkExpectedAction[]): void;
    (e: 'update:invalid', value: boolean): void;
}>();

let nextKey = 0;

function toRow(action: BenchmarkExpectedAction): Row {
    const text = formatJsonObject(action.arguments);

    return {
        key: nextKey++,
        tool: action.tool,
        argumentsText: text,
        argumentsError: null,
        lastValid: parseJsonObject(text).value ?? {},
    };
}

const rows = ref<Row[]>(props.modelValue.map(toRow));

function publish(): void {
    emit(
        'update:modelValue',
        rows.value.map((row) => ({
            tool: row.tool,
            arguments: row.lastValid,
        })),
    );
    emit(
        'update:invalid',
        rows.value.some((row) => row.argumentsError !== null),
    );
}

function parseArguments(row: Row): void {
    const parsed = parseJsonObject(row.argumentsText);

    row.argumentsError = parsed.error;

    if (parsed.value !== null) {
        row.lastValid = parsed.value;
    }

    publish();
}

function add(): void {
    rows.value.push(toRow({ tool: props.writeTools[0] ?? '', arguments: {} }));
    publish();
}

function remove(index: number): void {
    rows.value.splice(index, 1);
    publish();
}

/** A saved tool the agent no longer exposes stays selectable. */
function toolOptions(current: string): string[] {
    return current !== '' && !props.writeTools.includes(current)
        ? [current, ...props.writeTools]
        : props.writeTools;
}

function errorFor(index: number, field: string): string | undefined {
    return props.errors?.[`scoring.expected.${index}.${field}`];
}
</script>

<template>
    <section class="grid gap-3" data-expected-actions>
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="text-[13px] font-semibold">Expected actions</h2>
                <p class="text-[12px] text-muted-foreground">
                    The write calls a good answer makes. Arguments are compared
                    as a subset: extra arguments from the model do not count
                    against it.
                </p>
            </div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="h-7 shrink-0 gap-1 text-xs"
                data-expected-add
                @click="add"
            >
                <Plus class="size-3.5" />Add action
            </Button>
        </div>

        <p
            v-if="rows.length === 0"
            class="rounded-lg border border-dashed border-border p-3 text-[12.5px] text-muted-foreground"
            data-expected-empty
        >
            None expected: a good answer makes no write calls.
        </p>

        <div
            v-for="(row, index) in rows"
            :key="row.key"
            class="grid gap-3 rounded-lg border border-border bg-card p-3"
            data-expected-row
        >
            <div class="flex items-end gap-2">
                <label
                    class="grid flex-1 gap-1 text-[12px] text-muted-foreground"
                >
                    Tool
                    <select
                        v-model="row.tool"
                        class="h-8 rounded-md border border-input bg-background px-2 text-sm text-foreground"
                        data-expected-tool
                        @change="publish"
                    >
                        <option
                            v-for="name in toolOptions(row.tool)"
                            :key="name"
                            :value="name"
                        >
                            {{ name }}
                        </option>
                    </select>
                    <InputError :message="errorFor(index, 'tool')" />
                </label>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-8 shrink-0 text-destructive"
                    title="Remove"
                    data-expected-remove
                    @click="remove(index)"
                >
                    <Trash2 class="size-3.5" />
                </Button>
            </div>

            <label class="grid gap-1 text-[12px] text-muted-foreground">
                Arguments (JSON)
                <textarea
                    v-model="row.argumentsText"
                    rows="3"
                    class="rounded-md border border-input bg-background px-3 py-2 font-mono text-[12.5px] text-foreground"
                    data-expected-arguments
                    @blur="parseArguments(row)"
                />
                <span class="block min-h-5" data-expected-arguments-error>
                    <InputError
                        :message="
                            row.argumentsError ?? errorFor(index, 'arguments')
                        "
                    />
                </span>
            </label>
        </div>
    </section>
</template>
