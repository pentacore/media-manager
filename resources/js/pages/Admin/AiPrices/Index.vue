<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Plus, RefreshCcw, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import AiModelPriceController from '@/actions/App/Http/Controllers/Admin/AiModelPriceController';
import InputError from '@/components/InputError.vue';
import { Pill, StatCard } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';

interface PriceRow {
    id: number;
    provider: string;
    model: string;
    input_per_mtok: string;
    output_per_mtok: string;
    cache_read_per_mtok: string;
    cache_write_per_mtok: string;
    reasoning_per_mtok: string;
    batch_input_per_mtok: string | null;
    batch_output_per_mtok: string | null;
    batch_cache_read_per_mtok: string | null;
    batch_cache_write_per_mtok: string | null;
    batch_reasoning_per_mtok: string | null;
}

type RateField =
    | 'input_per_mtok'
    | 'output_per_mtok'
    | 'cache_read_per_mtok'
    | 'cache_write_per_mtok'
    | 'reasoning_per_mtok';

const BATCH_FIELD: Record<RateField, keyof PriceRow> = {
    input_per_mtok: 'batch_input_per_mtok',
    output_per_mtok: 'batch_output_per_mtok',
    cache_read_per_mtok: 'batch_cache_read_per_mtok',
    cache_write_per_mtok: 'batch_cache_write_per_mtok',
    reasoning_per_mtok: 'batch_reasoning_per_mtok',
};

const props = defineProps<{
    prices: PriceRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'AI Prices', href: AiModelPriceController.index.url() },
        ],
    },
});

const showCreateDialog = ref(false);
const editing = ref<PriceRow | null>(null);
const refreshing = ref(false);

function refreshPrices() {
    if (refreshing.value) {
        return;
    }

    refreshing.value = true;
    router.post(
        AiModelPriceController.refresh.url(),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                refreshing.value = false;
            },
        },
    );
}

function startEdit(price: PriceRow) {
    editing.value = { ...price };
}

function cancelEdit() {
    editing.value = null;
}

function destroy(price: PriceRow) {
    if (!confirm(`Remove pricing for ${price.provider}/${price.model}?`)) {
        return;
    }

    router.visit(AiModelPriceController.destroy.url(price.id), {
        method: 'delete',
        preserveScroll: true,
    });
}

function fmt(rate: string | null | undefined): string {
    if (rate === null || rate === undefined) {
        return '—';
    }

    const n = parseFloat(rate);

    if (Number.isNaN(n)) {
        return '—';
    }

    return `$${n.toFixed(2)}`;
}

const showBatch = ref(false);

function rateFor(price: PriceRow, field: RateField): string | null {
    if (showBatch.value) {
        const value = price[BATCH_FIELD[field]];

        return value === null || value === undefined ? null : String(value);
    }

    return price[field];
}

function hasBatch(price: PriceRow): boolean {
    return (
        price.batch_input_per_mtok !== null &&
        price.batch_input_per_mtok !== undefined
    );
}

const cheapest = ref(
    [...props.prices].sort(
        (a, b) =>
            parseFloat(a.input_per_mtok) +
            parseFloat(a.output_per_mtok) -
            (parseFloat(b.input_per_mtok) + parseFloat(b.output_per_mtok)),
    )[0] ?? null,
);

const priciest = ref(
    [...props.prices].sort(
        (a, b) =>
            parseFloat(b.input_per_mtok) +
            parseFloat(b.output_per_mtok) -
            (parseFloat(a.input_per_mtok) + parseFloat(a.output_per_mtok)),
    )[0] ?? null,
);
</script>

<template>
    <Head title="AI Model Prices" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero -->
        <div class="flex items-end justify-between gap-3">
            <div>
                <div class="mb-1.5 text-[13px] text-muted-foreground">
                    Admin <span class="text-fg-subtle">/</span> AI prices
                </div>
                <h1
                    class="text-[22px] leading-tight font-semibold tracking-tight"
                >
                    AI prices
                </h1>
                <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                    Per-million-token rates used to estimate cost on the AI
                    Usage dashboard. Add a row for any model you've used so its
                    spend shows up.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    class="h-7 gap-1.5 text-xs"
                    :disabled="refreshing"
                    @click="refreshPrices"
                >
                    <RefreshCcw
                        class="size-3.5"
                        :class="{ 'animate-spin': refreshing }"
                    />Refresh online
                </Button>
                <Dialog v-model:open="showCreateDialog">
                    <DialogTrigger as-child>
                        <Button size="sm" class="h-7 gap-1.5 text-xs">
                            <Plus class="size-3.5" />Add model price
                        </Button>
                    </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add model price</DialogTitle>
                    </DialogHeader>
                    <Form
                        v-bind="AiModelPriceController.store.post()"
                        class="space-y-4"
                        v-slot="{ errors, processing }"
                        @success="showCreateDialog = false"
                    >
                        <div class="space-y-2">
                            <Label for="provider">Provider</Label>
                            <Input
                                id="provider"
                                name="provider"
                                placeholder="openai, anthropic, gemini, …"
                            />
                            <InputError :message="errors.provider" />
                        </div>
                        <div class="space-y-2">
                            <Label for="model">Model</Label>
                            <Input
                                id="model"
                                name="model"
                                placeholder="gpt-5-mini"
                            />
                            <InputError :message="errors.model" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div
                                v-for="field in [
                                    ['input_per_mtok', 'Input ($/M)'],
                                    ['output_per_mtok', 'Output ($/M)'],
                                    ['cache_read_per_mtok', 'Cache Read ($/M)'],
                                    [
                                        'cache_write_per_mtok',
                                        'Cache Write ($/M)',
                                    ],
                                ] as const"
                                :key="field[0]"
                                class="space-y-2"
                            >
                                <Label :for="field[0]">{{ field[1] }}</Label>
                                <Input
                                    :id="field[0]"
                                    :name="field[0]"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                />
                                <InputError
                                    :message="
                                        errors[
                                            field[0] as
                                                | 'input_per_mtok'
                                                | 'output_per_mtok'
                                                | 'cache_read_per_mtok'
                                                | 'cache_write_per_mtok'
                                        ]
                                    "
                                />
                            </div>
                            <div class="col-span-2 space-y-2">
                                <Label for="reasoning_per_mtok"
                                    >Reasoning ($/M)</Label
                                >
                                <Input
                                    id="reasoning_per_mtok"
                                    name="reasoning_per_mtok"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                />
                                <InputError
                                    :message="errors.reasoning_per_mtok"
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="submit" :disabled="processing"
                                >Save</Button
                            >
                        </DialogFooter>
                    </Form>
                </DialogContent>
                </Dialog>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="grid gap-4 md:grid-cols-3">
            <StatCard
                label="Models priced"
                :value="prices.length"
                hint="rows in this catalog"
            />
            <div
                class="flex min-h-[110px] flex-col gap-2.5 rounded-xl border border-border bg-card p-5"
            >
                <span
                    class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                    >Cheapest</span
                >
                <div v-if="cheapest">
                    <div class="font-mono-tabular text-[15px] font-semibold">
                        {{ cheapest.model }}
                    </div>
                    <div class="text-xs text-muted-foreground">
                        {{ fmt(cheapest.input_per_mtok) }} in /
                        {{ fmt(cheapest.output_per_mtok) }} out
                    </div>
                </div>
                <div v-else class="text-sm text-fg-subtle">No data</div>
            </div>
            <div
                class="flex min-h-[110px] flex-col gap-2.5 rounded-xl border border-border bg-card p-5"
            >
                <span
                    class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                    >Priciest</span
                >
                <div v-if="priciest">
                    <div class="font-mono-tabular text-[15px] font-semibold">
                        {{ priciest.model }}
                    </div>
                    <div class="text-xs text-muted-foreground">
                        {{ fmt(priciest.input_per_mtok) }} in /
                        {{ fmt(priciest.output_per_mtok) }} out
                    </div>
                </div>
                <div v-else class="text-sm text-fg-subtle">No data</div>
            </div>
        </div>

        <!-- Models table -->
        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <div
                class="flex items-center justify-between gap-3 border-b border-border px-4 py-3"
            >
                <span
                    class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                >
                    Configured models
                </span>
                <div
                    class="inline-flex items-center rounded-md border border-border bg-bg-elev p-0.5 text-[12px]"
                    role="tablist"
                    aria-label="Pricing tier"
                >
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="!showBatch"
                        class="h-6 rounded px-2.5 font-medium transition-colors"
                        :class="
                            !showBatch
                                ? 'bg-card text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="showBatch = false"
                    >
                        Standard
                    </button>
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="showBatch"
                        class="h-6 rounded px-2.5 font-medium transition-colors"
                        :class="
                            showBatch
                                ? 'bg-card text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="showBatch = true"
                    >
                        Batch (50%)
                    </button>
                </div>
            </div>
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            v-for="h in [
                                'Model',
                                'Provider',
                                'Input',
                                'Output',
                                'Cache R',
                                'Cache W',
                                'Reasoning',
                                '',
                            ]"
                            :key="h"
                            class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            {{ h }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="price in prices"
                        :key="price.id"
                        class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                        :class="
                            showBatch && !hasBatch(price) ? 'opacity-50' : ''
                        "
                    >
                        <td class="px-3 py-2.5">
                            <div
                                class="font-mono-tabular text-[12.5px] font-medium"
                            >
                                {{ price.model }}
                            </div>
                            <div
                                v-if="showBatch && !hasBatch(price)"
                                class="text-[11px] text-fg-subtle"
                            >
                                no batch — showing standard
                            </div>
                        </td>
                        <td class="px-3 py-2.5">
                            <Pill>{{ price.provider }}</Pill>
                        </td>
                        <td class="font-mono-tabular px-3 py-2.5 text-right">
                            {{
                                fmt(
                                    rateFor(price, 'input_per_mtok') ??
                                        price.input_per_mtok,
                                )
                            }}
                        </td>
                        <td class="font-mono-tabular px-3 py-2.5 text-right">
                            {{
                                fmt(
                                    rateFor(price, 'output_per_mtok') ??
                                        price.output_per_mtok,
                                )
                            }}
                        </td>
                        <td
                            class="font-mono-tabular px-3 py-2.5 text-right text-fg-subtle"
                        >
                            {{
                                fmt(
                                    rateFor(price, 'cache_read_per_mtok') ??
                                        price.cache_read_per_mtok,
                                )
                            }}
                        </td>
                        <td
                            class="font-mono-tabular px-3 py-2.5 text-right text-fg-subtle"
                        >
                            {{
                                fmt(
                                    rateFor(price, 'cache_write_per_mtok') ??
                                        price.cache_write_per_mtok,
                                )
                            }}
                        </td>
                        <td
                            class="font-mono-tabular px-3 py-2.5 text-right text-fg-subtle"
                        >
                            {{
                                fmt(
                                    rateFor(price, 'reasoning_per_mtok') ??
                                        price.reasoning_per_mtok,
                                )
                            }}
                        </td>
                        <td class="px-3 py-2.5 text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="h-7 px-2 text-xs"
                                    @click="startEdit(price)"
                                >
                                    Edit
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="size-7 p-0 text-destructive hover:text-destructive"
                                    @click="destroy(price)"
                                >
                                    <Trash2 class="size-3.5" />
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="prices.length === 0">
                        <td
                            colspan="8"
                            class="px-3 py-8 text-center text-sm text-fg-subtle"
                        >
                            No models priced yet. Click "Add model price".
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Edit dialog -->
        <Dialog
            :open="editing !== null"
            @update:open="(v) => !v && cancelEdit()"
        >
            <DialogContent v-if="editing">
                <DialogHeader>
                    <DialogTitle>
                        Edit {{ editing.provider }} / {{ editing.model }}
                    </DialogTitle>
                </DialogHeader>
                <Form
                    v-bind="AiModelPriceController.update.form(editing.id)"
                    class="space-y-4"
                    v-slot="{ errors, processing }"
                    @success="cancelEdit"
                >
                    <div class="grid grid-cols-2 gap-4">
                        <div
                            v-for="field in [
                                ['edit_input', 'input_per_mtok', 'Input ($/M)'],
                                [
                                    'edit_output',
                                    'output_per_mtok',
                                    'Output ($/M)',
                                ],
                                [
                                    'edit_cache_r',
                                    'cache_read_per_mtok',
                                    'Cache Read ($/M)',
                                ],
                                [
                                    'edit_cache_w',
                                    'cache_write_per_mtok',
                                    'Cache Write ($/M)',
                                ],
                            ] as const"
                            :key="field[0]"
                            class="space-y-2"
                        >
                            <Label :for="field[0]">{{ field[2] }}</Label>
                            <Input
                                :id="field[0]"
                                :name="field[1]"
                                type="number"
                                step="0.0001"
                                min="0"
                                :default-value="
                                    editing[
                                        field[1] as
                                            | 'input_per_mtok'
                                            | 'output_per_mtok'
                                            | 'cache_read_per_mtok'
                                            | 'cache_write_per_mtok'
                                    ]
                                "
                            />
                            <InputError
                                :message="
                                    errors[
                                        field[1] as
                                            | 'input_per_mtok'
                                            | 'output_per_mtok'
                                            | 'cache_read_per_mtok'
                                            | 'cache_write_per_mtok'
                                    ]
                                "
                            />
                        </div>
                        <div class="col-span-2 space-y-2">
                            <Label for="edit_reasoning">Reasoning ($/M)</Label>
                            <Input
                                id="edit_reasoning"
                                name="reasoning_per_mtok"
                                type="number"
                                step="0.0001"
                                min="0"
                                :default-value="editing.reasoning_per_mtok"
                            />
                            <InputError :message="errors.reasoning_per_mtok" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="submit" :disabled="processing">
                            Save
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
