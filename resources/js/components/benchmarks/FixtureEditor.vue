<script setup lang="ts">
import { ArrowDown, ArrowUp, Plus, Trash2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatJsonObject, parseJsonObject } from './jsonObject';
import type { BenchmarkFixture } from './types';

type Row = {
    key: number;
    tool: string;
    argumentsText: string;
    argumentsError: string | null;
    result: string;
    lastValid: BenchmarkFixture['arguments'];
};

const props = defineProps<{
    modelValue: BenchmarkFixture[];
    /** Server validation errors keyed like `fixtures.0.tool`. */
    errors?: Record<string, string | undefined>;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: BenchmarkFixture[]): void;
    (e: 'update:invalid', value: boolean): void;
}>();

let nextKey = 0;

function toRow(fixture: BenchmarkFixture): Row {
    return {
        key: nextKey++,
        tool: fixture.tool,
        argumentsText: formatJsonObject(fixture.arguments),
        argumentsError: null,
        result: fixture.result,
        lastValid:
            parseJsonObject(formatJsonObject(fixture.arguments)).value ?? {},
    };
}

const rows = ref<Row[]>(props.modelValue.map(toRow));

/** Rows are owned here after mount; only an outside reset (a different array length) reloads them. */
watch(
    () => props.modelValue,
    (value) => {
        if (value.length !== rows.value.length) {
            rows.value = value.map(toRow);
        }
    },
);

function publish(): void {
    emit(
        'update:modelValue',
        rows.value.map((row) => ({
            tool: row.tool,
            arguments: row.lastValid,
            result: row.result,
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
    rows.value.push(toRow({ tool: '', arguments: {}, result: '' }));
    publish();
}

function remove(index: number): void {
    rows.value.splice(index, 1);
    publish();
}

function move(index: number, offset: -1 | 1): void {
    const target = index + offset;

    if (target < 0 || target >= rows.value.length) {
        return;
    }

    const [row] = rows.value.splice(index, 1);

    rows.value.splice(target, 0, row);
    publish();
}

function errorFor(index: number, field: string): string | undefined {
    return props.errors?.[`fixtures.${index}.${field}`];
}
</script>

<template>
    <section class="grid gap-3" data-fixture-editor>
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="text-[13px] font-semibold">Recorded tool results</h2>
                <p class="text-[12px] text-muted-foreground">
                    When the model calls one of these tools, it gets the
                    recorded result back instead of touching a real service.
                    Calls are matched in order.
                </p>
            </div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="h-7 shrink-0 gap-1 text-xs"
                data-fixture-add
                @click="add"
            >
                <Plus class="size-3.5" />Add fixture
            </Button>
        </div>

        <p
            v-if="rows.length === 0"
            class="rounded-lg border border-dashed border-border p-3 text-[12.5px] text-muted-foreground"
            data-fixture-empty
        >
            No recorded tool results. The model will run with no tool output.
        </p>

        <div
            v-for="(row, index) in rows"
            :key="row.key"
            class="grid gap-3 rounded-lg border border-border bg-card p-3"
            data-fixture-row
        >
            <div class="flex items-end gap-2">
                <label
                    class="grid flex-1 gap-1 text-[12px] text-muted-foreground"
                >
                    Tool name
                    <Input
                        v-model="row.tool"
                        class="h-8 font-mono text-sm"
                        data-fixture-tool
                        @update:model-value="publish"
                    />
                    <InputError :message="errorFor(index, 'tool')" />
                </label>
                <div class="flex shrink-0 gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-8"
                        title="Move up"
                        :disabled="index === 0"
                        data-fixture-up
                        @click="move(index, -1)"
                    >
                        <ArrowUp class="size-3.5" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-8"
                        title="Move down"
                        :disabled="index === rows.length - 1"
                        data-fixture-down
                        @click="move(index, 1)"
                    >
                        <ArrowDown class="size-3.5" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-8 text-destructive"
                        title="Remove"
                        data-fixture-remove
                        @click="remove(index)"
                    >
                        <Trash2 class="size-3.5" />
                    </Button>
                </div>
            </div>

            <label class="grid gap-1 text-[12px] text-muted-foreground">
                Arguments (JSON)
                <textarea
                    v-model="row.argumentsText"
                    rows="3"
                    class="rounded-md border border-input bg-background px-3 py-2 font-mono text-[12.5px] text-foreground"
                    data-fixture-arguments
                    @blur="parseArguments(row)"
                />
                <span class="block min-h-5" data-fixture-arguments-error>
                    <InputError
                        :message="
                            row.argumentsError ?? errorFor(index, 'arguments')
                        "
                    />
                </span>
            </label>

            <label class="grid gap-1 text-[12px] text-muted-foreground">
                Result
                <textarea
                    v-model="row.result"
                    rows="4"
                    class="rounded-md border border-input bg-background px-3 py-2 font-mono text-[12.5px] text-foreground"
                    data-fixture-result
                    @input="publish"
                />
                <InputError :message="errorFor(index, 'result')" />
            </label>
        </div>
    </section>
</template>
