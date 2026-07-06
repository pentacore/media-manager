<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Plus, RefreshCcw, Trash2 } from '@lucide/vue';
import { onMounted, onUnmounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import AiFreeUsagePoolController from '@/actions/App/Http/Controllers/Admin/AiFreeUsagePoolController';
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
import { useWebSocket } from '@/composables/useWebSocket';
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
    free_usage_pool_id: number | null;
    rate_limits: {
        id: number;
        metric: 'requests' | 'tokens';
        period: 'minute' | 'hour' | 'day';
        limit_value: number;
    }[];
}

interface PoolRow {
    id: number;
    name: string;
    period: 'daily' | 'weekly' | 'monthly';
    unified: boolean;
    free_input_tokens: number | null;
    free_output_tokens: number | null;
    free_total_tokens: number | null;
    documentation_url: string | null;
    prices_count: number;
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
    pools: PoolRow[];
    refresh_running: boolean;
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
const refreshing = ref(props.refresh_running);

const showPoolCreateDialog = ref(false);
const editingPool = ref<PoolRow | null>(null);
const poolCreateUnified = ref(false);
const poolEditUnified = ref(false);

interface RateLimitDraft {
    metric: 'requests' | 'tokens';
    period: 'minute' | 'hour' | 'day';
    limit_value: number | null;
}

const RATE_LIMIT_METRICS: Array<[RateLimitDraft['metric'], string]> = [
    ['requests', 'Requests'],
    ['tokens', 'Tokens'],
];

const RATE_LIMIT_PERIODS: Array<[RateLimitDraft['period'], string]> = [
    ['minute', 'Per minute'],
    ['hour', 'Per hour'],
    ['day', 'Per day'],
];

const createRateLimits = ref<RateLimitDraft[]>([]);
const editRateLimits = ref<RateLimitDraft[]>([]);

function addRateLimit(list: RateLimitDraft[]) {
    list.push({ metric: 'requests', period: 'minute', limit_value: null });
}

function removeRateLimit(list: RateLimitDraft[], index: number) {
    list.splice(index, 1);
}

function startPoolEdit(pool: PoolRow) {
    editingPool.value = { ...pool };
    poolEditUnified.value = pool.unified;
}

function cancelPoolEdit() {
    editingPool.value = null;
}

function destroyPool(pool: PoolRow) {
    if (
        !confirm(
            `Remove pool "${pool.name}"? Member models keep their pricing but lose the free tier.`,
        )
    ) {
        return;
    }

    router.visit(AiFreeUsagePoolController.destroy.url(pool.id), {
        method: 'delete',
        preserveScroll: true,
    });
}

function formatTokens(value: number | null): string {
    return value === null ? '—' : new Intl.NumberFormat().format(value);
}

const PRICE_REFRESH_CHANNEL = 'admin.ai-prices';

interface PriceRefreshPayload {
    state: 'queued' | 'running' | 'succeeded' | 'failed';
    triggered_by: { id: number; name: string } | null;
    summary: string | null;
    error: string | null;
    added: number | null;
    total: number | null;
    occurred_at: string;
}

function refreshPrices() {
    if (refreshing.value) {
        return;
    }

    // Optimistically flip the button so admins get instant feedback even
    // before the broadcast lands. The job will keep us in this state until
    // succeeded/failed arrives.
    refreshing.value = true;
    router.post(
        AiModelPriceController.refresh.url(),
        {},
        {
            preserveScroll: true,
            preserveState: true,
        },
    );
}

function handleRefreshState(payload: PriceRefreshPayload): void {
    if (payload.state === 'queued' || payload.state === 'running') {
        refreshing.value = true;

        return;
    }

    refreshing.value = false;

    if (payload.state === 'succeeded') {
        const triggered = payload.triggered_by
            ? ` triggered by ${payload.triggered_by.name}`
            : '';
        toast.success('Price refresh complete', {
            description: `${payload.added ?? 0} new, ${payload.total ?? 0} total${triggered}.`,
        });
        router.reload({ only: ['prices'] });

        return;
    }

    toast.error('Price refresh failed', {
        description: payload.error ?? 'Unknown error',
    });
}

const { privateChannel, leaveChannel } = useWebSocket();

onMounted(() => {
    privateChannel(PRICE_REFRESH_CHANNEL).listen(
        '.AiPriceRefreshStateChanged',
        (event: PriceRefreshPayload) => handleRefreshState(event),
    );
});

onUnmounted(() => {
    leaveChannel(PRICE_REFRESH_CHANNEL);
});

function startEdit(price: PriceRow) {
    editing.value = { ...price };
    editRateLimits.value = price.rate_limits.map((limit) => ({
        metric: limit.metric,
        period: limit.period,
        limit_value: limit.limit_value,
    }));
}

function onCreateSuccess() {
    showCreateDialog.value = false;
    createRateLimits.value = [];
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
                            @success="onCreateSuccess"
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
                                        [
                                            'cache_read_per_mtok',
                                            'Cache Read ($/M)',
                                        ],
                                        [
                                            'cache_write_per_mtok',
                                            'Cache Write ($/M)',
                                        ],
                                    ] as const"
                                    :key="field[0]"
                                    class="space-y-2"
                                >
                                    <Label :for="field[0]">{{
                                        field[1]
                                    }}</Label>
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
                                <div class="col-span-2 space-y-2">
                                    <Label for="free_usage_pool_id"
                                        >Free usage pool</Label
                                    >
                                    <select
                                        id="free_usage_pool_id"
                                        name="free_usage_pool_id"
                                        class="h-9 w-full rounded-md border border-border bg-card px-2 text-sm"
                                    >
                                        <option value="">No pool</option>
                                        <option
                                            v-for="pool in pools"
                                            :key="pool.id"
                                            :value="pool.id"
                                        >
                                            {{ pool.name }}
                                        </option>
                                    </select>
                                    <InputError
                                        :message="errors.free_usage_pool_id"
                                    />
                                </div>
                                <div class="col-span-2 space-y-2">
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <Label>Rate limits</Label>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            @click="
                                                addRateLimit(createRateLimits)
                                            "
                                        >
                                            <Plus class="h-3.5 w-3.5" /> Add
                                            limit
                                        </Button>
                                    </div>
                                    <div
                                        v-for="(limit, i) in createRateLimits"
                                        :key="i"
                                        class="flex items-center gap-2"
                                    >
                                        <select
                                            v-model="limit.metric"
                                            :name="`rate_limits[${i}][metric]`"
                                            class="h-9 w-32 rounded-md border border-border bg-card px-2 text-sm"
                                        >
                                            <option
                                                v-for="[
                                                    value,
                                                    label,
                                                ] in RATE_LIMIT_METRICS"
                                                :key="value"
                                                :value="value"
                                            >
                                                {{ label }}
                                            </option>
                                        </select>
                                        <select
                                            v-model="limit.period"
                                            :name="`rate_limits[${i}][period]`"
                                            class="h-9 w-32 rounded-md border border-border bg-card px-2 text-sm"
                                        >
                                            <option
                                                v-for="[
                                                    value,
                                                    label,
                                                ] in RATE_LIMIT_PERIODS"
                                                :key="value"
                                                :value="value"
                                            >
                                                {{ label }}
                                            </option>
                                        </select>
                                        <Input
                                            v-model.number="limit.limit_value"
                                            :name="`rate_limits[${i}][limit_value]`"
                                            type="number"
                                            min="1"
                                            placeholder="Limit"
                                            class="flex-1"
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            @click="
                                                removeRateLimit(
                                                    createRateLimits,
                                                    i,
                                                )
                                            "
                                        >
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </Button>
                                    </div>
                                    <InputError
                                        :message="
                                            Object.entries(errors)
                                                .filter(([key]) =>
                                                    key.startsWith(
                                                        'rate_limits',
                                                    ),
                                                )
                                                .map(([, message]) => message)
                                                .join(' ')
                                        "
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

        <!-- Free usage pools -->
        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <div
                class="flex items-center justify-between gap-3 border-b border-border px-4 py-3"
            >
                <span
                    class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                >
                    Free usage pools
                </span>
                <Dialog v-model:open="showPoolCreateDialog">
                    <DialogTrigger as-child>
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-7 gap-1.5 text-xs"
                        >
                            <Plus class="size-3.5" />Add pool
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Add free usage pool</DialogTitle>
                        </DialogHeader>
                        <Form
                            v-bind="AiFreeUsagePoolController.store.post()"
                            class="space-y-4"
                            v-slot="{ errors, processing }"
                            @success="showPoolCreateDialog = false"
                        >
                            <div class="space-y-2">
                                <Label for="pool_name">Name</Label>
                                <Input
                                    id="pool_name"
                                    name="name"
                                    placeholder="Gemini free tier"
                                />
                                <InputError :message="errors.name" />
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="space-y-2">
                                    <Label for="pool_period"
                                        >Reset period</Label
                                    >
                                    <select
                                        id="pool_period"
                                        name="period"
                                        class="h-9 w-full rounded-md border border-border bg-card px-2 text-sm"
                                    >
                                        <option value="daily">Daily</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly" selected>
                                            Monthly
                                        </option>
                                    </select>
                                    <InputError :message="errors.period" />
                                </div>
                                <div class="space-y-2">
                                    <Label for="pool_unified"
                                        >Unified budget</Label
                                    >
                                    <label
                                        class="flex h-9 items-center gap-2 text-sm"
                                    >
                                        <input
                                            type="hidden"
                                            name="unified"
                                            value="0"
                                        />
                                        <input
                                            id="pool_unified"
                                            v-model="poolCreateUnified"
                                            type="checkbox"
                                            name="unified"
                                            value="1"
                                            class="size-4"
                                        />
                                        input + output share one budget
                                    </label>
                                    <InputError :message="errors.unified" />
                                </div>
                            </div>
                            <div v-if="poolCreateUnified" class="space-y-2">
                                <Label for="pool_free_total"
                                    >Free tokens / period</Label
                                >
                                <Input
                                    id="pool_free_total"
                                    name="free_total_tokens"
                                    type="number"
                                    min="0"
                                />
                                <InputError
                                    :message="errors.free_total_tokens"
                                />
                            </div>
                            <div v-else class="grid grid-cols-2 gap-4">
                                <div class="space-y-2">
                                    <Label for="pool_free_input"
                                        >Free input / period</Label
                                    >
                                    <Input
                                        id="pool_free_input"
                                        name="free_input_tokens"
                                        type="number"
                                        min="0"
                                        placeholder="leave blank if none"
                                    />
                                    <InputError
                                        :message="errors.free_input_tokens"
                                    />
                                </div>
                                <div class="space-y-2">
                                    <Label for="pool_free_output"
                                        >Free output / period</Label
                                    >
                                    <Input
                                        id="pool_free_output"
                                        name="free_output_tokens"
                                        type="number"
                                        min="0"
                                        placeholder="leave blank if none"
                                    />
                                    <InputError
                                        :message="errors.free_output_tokens"
                                    />
                                </div>
                            </div>
                            <div class="space-y-2">
                                <Label for="pool_doc_url"
                                    >Documentation URL</Label
                                >
                                <Input
                                    id="pool_doc_url"
                                    name="documentation_url"
                                    type="url"
                                    placeholder="https://…"
                                />
                                <InputError
                                    :message="errors.documentation_url"
                                />
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
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th
                                v-for="h in [
                                    'Pool',
                                    'Period',
                                    'Budget',
                                    'Models',
                                    'Docs',
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
                            v-for="pool in pools"
                            :key="pool.id"
                            class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                        >
                            <td class="px-3 py-2.5 font-medium">
                                {{ pool.name }}
                            </td>
                            <td class="px-3 py-2.5">
                                <Pill>{{ pool.period }}</Pill>
                            </td>
                            <td class="font-mono-tabular px-3 py-2.5">
                                <template v-if="pool.unified">
                                    {{ formatTokens(pool.free_total_tokens) }}
                                    total
                                </template>
                                <template v-else>
                                    {{ formatTokens(pool.free_input_tokens) }}
                                    in /
                                    {{ formatTokens(pool.free_output_tokens) }}
                                    out
                                </template>
                            </td>
                            <td class="px-3 py-2.5">
                                {{ pool.prices_count }}
                            </td>
                            <td class="px-3 py-2.5">
                                <a
                                    v-if="pool.documentation_url"
                                    :href="pool.documentation_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="underline hover:text-foreground"
                                    >docs</a
                                >
                                <span v-else class="text-fg-subtle">—</span>
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <div class="flex justify-end gap-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="h-7 px-2 text-xs"
                                        @click="startPoolEdit(pool)"
                                    >
                                        Edit
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="size-7 p-0 text-destructive hover:text-destructive"
                                        @click="destroyPool(pool)"
                                    >
                                        <Trash2 class="size-3.5" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="pools.length === 0">
                            <td
                                colspan="6"
                                class="px-3 py-6 text-center text-sm text-fg-subtle"
                            >
                                No pools yet. Pools let several models share one
                                free-usage budget.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Edit pool dialog -->
        <Dialog
            :open="editingPool !== null"
            @update:open="(v) => !v && cancelPoolEdit()"
        >
            <DialogContent v-if="editingPool">
                <DialogHeader>
                    <DialogTitle>Edit {{ editingPool.name }}</DialogTitle>
                </DialogHeader>
                <Form
                    v-bind="
                        AiFreeUsagePoolController.update.form(editingPool.id)
                    "
                    class="space-y-4"
                    v-slot="{ errors, processing }"
                    @success="cancelPoolEdit"
                >
                    <div class="space-y-2">
                        <Label for="edit_pool_name">Name</Label>
                        <Input
                            id="edit_pool_name"
                            name="name"
                            :default-value="editingPool.name"
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <Label for="edit_pool_period">Reset period</Label>
                            <select
                                id="edit_pool_period"
                                name="period"
                                class="h-9 w-full rounded-md border border-border bg-card px-2 text-sm"
                            >
                                <option
                                    v-for="p in ['daily', 'weekly', 'monthly']"
                                    :key="p"
                                    :value="p"
                                    :selected="editingPool.period === p"
                                >
                                    {{ p.charAt(0).toUpperCase() + p.slice(1) }}
                                </option>
                            </select>
                            <InputError :message="errors.period" />
                        </div>
                        <div class="space-y-2">
                            <Label for="edit_pool_unified"
                                >Unified budget</Label
                            >
                            <label class="flex h-9 items-center gap-2 text-sm">
                                <input type="hidden" name="unified" value="0" />
                                <input
                                    id="edit_pool_unified"
                                    v-model="poolEditUnified"
                                    type="checkbox"
                                    name="unified"
                                    value="1"
                                    class="size-4"
                                />
                                input + output share one budget
                            </label>
                            <InputError :message="errors.unified" />
                        </div>
                    </div>
                    <div v-if="poolEditUnified" class="space-y-2">
                        <Label for="edit_pool_free_total"
                            >Free tokens / period</Label
                        >
                        <Input
                            id="edit_pool_free_total"
                            name="free_total_tokens"
                            type="number"
                            min="0"
                            :default-value="editingPool.free_total_tokens ?? ''"
                        />
                        <InputError :message="errors.free_total_tokens" />
                    </div>
                    <div v-else class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <Label for="edit_pool_free_input"
                                >Free input / period</Label
                            >
                            <Input
                                id="edit_pool_free_input"
                                name="free_input_tokens"
                                type="number"
                                min="0"
                                placeholder="leave blank if none"
                                :default-value="
                                    editingPool.free_input_tokens ?? ''
                                "
                            />
                            <InputError :message="errors.free_input_tokens" />
                        </div>
                        <div class="space-y-2">
                            <Label for="edit_pool_free_output"
                                >Free output / period</Label
                            >
                            <Input
                                id="edit_pool_free_output"
                                name="free_output_tokens"
                                type="number"
                                min="0"
                                placeholder="leave blank if none"
                                :default-value="
                                    editingPool.free_output_tokens ?? ''
                                "
                            />
                            <InputError :message="errors.free_output_tokens" />
                        </div>
                    </div>
                    <div class="space-y-2">
                        <Label for="edit_pool_doc_url">Documentation URL</Label>
                        <Input
                            id="edit_pool_doc_url"
                            name="documentation_url"
                            type="url"
                            placeholder="https://…"
                            :default-value="editingPool.documentation_url ?? ''"
                        />
                        <InputError :message="errors.documentation_url" />
                    </div>
                    <DialogFooter>
                        <Button type="submit" :disabled="processing">
                            Save
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>

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
            <div class="overflow-x-auto">
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
                                showBatch && !hasBatch(price)
                                    ? 'opacity-50'
                                    : ''
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
                            <td
                                class="font-mono-tabular px-3 py-2.5 text-right"
                            >
                                {{
                                    fmt(
                                        rateFor(price, 'input_per_mtok') ??
                                            price.input_per_mtok,
                                    )
                                }}
                            </td>
                            <td
                                class="font-mono-tabular px-3 py-2.5 text-right"
                            >
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
                                        rateFor(
                                            price,
                                            'cache_write_per_mtok',
                                        ) ?? price.cache_write_per_mtok,
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
                        <div class="col-span-2 space-y-2">
                            <Label for="edit_free_usage_pool_id"
                                >Free usage pool</Label
                            >
                            <select
                                id="edit_free_usage_pool_id"
                                name="free_usage_pool_id"
                                class="h-9 w-full rounded-md border border-border bg-card px-2 text-sm"
                            >
                                <option
                                    value=""
                                    :selected="
                                        editing.free_usage_pool_id === null
                                    "
                                >
                                    No pool
                                </option>
                                <option
                                    v-for="pool in pools"
                                    :key="pool.id"
                                    :value="pool.id"
                                    :selected="
                                        editing.free_usage_pool_id === pool.id
                                    "
                                >
                                    {{ pool.name }}
                                </option>
                            </select>
                            <InputError :message="errors.free_usage_pool_id" />
                        </div>
                        <div class="col-span-2 space-y-2">
                            <div class="flex items-center justify-between">
                                <Label>Rate limits</Label>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="addRateLimit(editRateLimits)"
                                >
                                    <Plus class="h-3.5 w-3.5" /> Add limit
                                </Button>
                            </div>
                            <div
                                v-for="(limit, i) in editRateLimits"
                                :key="i"
                                class="flex items-center gap-2"
                            >
                                <select
                                    v-model="limit.metric"
                                    :name="`rate_limits[${i}][metric]`"
                                    class="h-9 w-32 rounded-md border border-border bg-card px-2 text-sm"
                                >
                                    <option
                                        v-for="[
                                            value,
                                            label,
                                        ] in RATE_LIMIT_METRICS"
                                        :key="value"
                                        :value="value"
                                    >
                                        {{ label }}
                                    </option>
                                </select>
                                <select
                                    v-model="limit.period"
                                    :name="`rate_limits[${i}][period]`"
                                    class="h-9 w-32 rounded-md border border-border bg-card px-2 text-sm"
                                >
                                    <option
                                        v-for="[
                                            value,
                                            label,
                                        ] in RATE_LIMIT_PERIODS"
                                        :key="value"
                                        :value="value"
                                    >
                                        {{ label }}
                                    </option>
                                </select>
                                <Input
                                    v-model.number="limit.limit_value"
                                    :name="`rate_limits[${i}][limit_value]`"
                                    type="number"
                                    min="1"
                                    placeholder="Limit"
                                    class="flex-1"
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    @click="removeRateLimit(editRateLimits, i)"
                                >
                                    <Trash2 class="h-3.5 w-3.5" />
                                </Button>
                            </div>
                            <InputError
                                :message="
                                    Object.entries(errors)
                                        .filter(([key]) =>
                                            key.startsWith('rate_limits'),
                                        )
                                        .map(([, message]) => message)
                                        .join(' ')
                                "
                            />
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
