<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Check, Minus, Plus, RefreshCcw, Trash2 } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AiFreeUsagePoolController from '@/actions/App/Http/Controllers/Admin/AiFreeUsagePoolController';
import AiModelPriceController from '@/actions/App/Http/Controllers/Admin/AiModelPriceController';
import {
    AddFromCatalogDialog,
    BulkDeletePricesDialog,
    BulkEditPricesDialog,
    CreatePriceDialog,
    SOURCE_LABELS,
} from '@/components/ai-prices';
import type { RateLimitDraft } from '@/components/ai-prices';
import InputError from '@/components/InputError.vue';
import {
    Pill,
    PoolFormFields,
    RateLimitEditor,
    StatCard,
    TimeStamp,
    Toggle,
} from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useConfirm } from '@/composables/useConfirm';
import { useWebSocket } from '@/composables/useWebSocket';
import type { ChannelLease } from '@/composables/useWebSocket';
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
    search_unit_per_k: string;
    batch_search_unit_per_k: string | null;
    free_usage_pool_id: number | null;
    pricing_source:
        | 'seed'
        | 'models_dev'
        | 'first_party'
        | 'manual'
        | 'legacy'
        | 'openrouter'
        | 'litellm'
        | 'xai_api'
        | 'feed_consensus'
        | null;
    pricing_source_url: string | null;
    pricing_source_updated_at: string | null;
    pricing_synced_at: string | null;
    pricing_verified_at: string | null;
    is_price_locked: boolean;
    supports_reasoning: boolean | null;
    reasoning_levels: string[] | null;
    automatic_updates_enabled: boolean;
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
    overflow_behavior: 'fit_or_paid' | 'split';
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
    rate_limit_metrics: Array<{ value: string; label: string }>;
    rate_limit_periods: Array<{ value: string; label: string }>;
    catalog_providers: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'AI Prices', href: AiModelPriceController.index.url() },
        ],
    },
});

const editing = ref<PriceRow | null>(null);
const refreshing = ref(props.refresh_running);

const showPoolCreateDialog = ref(false);
const editingPool = ref<PoolRow | null>(null);

const editRateLimits = ref<RateLimitDraft[]>([]);

const editPoolId = ref('none');

const REASONING_LEVEL_OPTIONS = [
    'none',
    'low',
    'medium',
    'high',
    'xhigh',
    'max',
] as const;

const editSupportsReasoning = ref<'yes' | 'no' | 'unknown'>('unknown');
const editReasoningLevels = ref<string[]>([]);

// Whether a manually managed row opts into online refreshes. Edit mirrors
// the row's state.
const editAutomaticUpdates = ref(false);

// Tracks whether the admin actually interacted with the edit dialog's toggle
// this session. The hidden `automatic_updates_enabled` field is submitted ONLY
// when touched: an untouched toggle omits the field so a rate edit falls
// through to the backend's price-change locking rule, rather than the prefilled
// value reading as an explicit re-enable.
const editAutomaticUpdatesTouched = ref(false);

function onEditAutomaticUpdatesChange(value: boolean): void {
    editAutomaticUpdates.value = value;
    editAutomaticUpdatesTouched.value = true;
}

type PricingSource = NonNullable<PriceRow['pricing_source']>;

const SOURCE_VARIANTS: Record<
    PricingSource,
    'default' | 'ok' | 'warn' | 'info'
> = {
    seed: 'default',
    models_dev: 'ok',
    first_party: 'ok',
    manual: 'info',
    legacy: 'warn',
    openrouter: 'ok',
    litellm: 'ok',
    xai_api: 'ok',
    feed_consensus: 'ok',
};

function sourceLabel(source: PriceRow['pricing_source']): string {
    return source ? SOURCE_LABELS[source] : '—';
}

function sourceVariant(
    source: PricingSource,
): 'default' | 'ok' | 'warn' | 'info' {
    return SOURCE_VARIANTS[source];
}

/**
 * Guards the pricing-source pill link so only http(s) URLs ever become an
 * anchor. Any other scheme (javascript:, data:, etc.) or an unparseable value
 * returns null, and the template falls back to a plain, non-clickable pill.
 */
function safeSourceHref(url: string | null): string | null {
    if (url === null) {
        return null;
    }

    try {
        const { protocol } = new URL(url);

        return protocol === 'http:' || protocol === 'https:' ? url : null;
    } catch {
        return null;
    }
}

function startPoolEdit(pool: PoolRow) {
    editingPool.value = { ...pool };
}

function cancelPoolEdit() {
    editingPool.value = null;
}

const { confirm } = useConfirm();

async function destroyPool(pool: PoolRow): Promise<void> {
    const confirmed = await confirm({
        title: `Remove pool "${pool.name}"?`,
        description: 'Member models keep their pricing but lose the free tier.',
        confirmLabel: 'Remove',
        destructive: true,
    });

    if (!confirmed) {
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
    run_id: number | null;
    final_result: 'succeeded' | 'partial' | 'failed' | null;
    models_dev_status: string | null;
    providers_requested: number | null;
    providers_succeeded: number | null;
    providers_failed: number | null;
    models_created: number | null;
    models_updated: number | null;
    models_unchanged: number | null;
    models_locked: number | null;
    models_rejected: number | null;
    models_tiered: number | null;
    fallback_providers: string[] | null;
    error_message: string | null;
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

/**
 * Compact created/updated/locked/rejected counter string for the enriched
 * refresh toasts.
 */
function refreshCounts(payload: PriceRefreshPayload): string {
    return [
        `${payload.models_created ?? 0} created`,
        `${payload.models_updated ?? 0} updated`,
        `${payload.models_locked ?? 0} locked`,
        `${payload.models_rejected ?? 0} rejected`,
    ].join(', ');
}

function handleRefreshState(payload: PriceRefreshPayload): void {
    if (payload.state === 'queued' || payload.state === 'running') {
        refreshing.value = true;

        return;
    }

    refreshing.value = false;

    // Enriched runs carry a final_result; legacy payloads leave it null and
    // keep the original added/total success behavior below.
    if (payload.final_result !== null) {
        if (payload.final_result === 'succeeded') {
            toast.success('Price refresh complete', {
                description: `${refreshCounts(payload)}.`,
            });
            router.reload({ only: ['prices'] });

            return;
        }

        if (payload.final_result === 'partial') {
            const fallback =
                payload.fallback_providers &&
                payload.fallback_providers.length > 0
                    ? ` Fallback: ${payload.fallback_providers.join(', ')}.`
                    : '';
            toast.warning('Price refresh partially completed', {
                description: `${refreshCounts(payload)}.${fallback}`,
            });
            router.reload({ only: ['prices'] });

            return;
        }

        toast.error('Price refresh failed', {
            description:
                payload.error_message ?? payload.error ?? 'Unknown error',
        });

        return;
    }

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

const { acquirePrivateChannel } = useWebSocket();
let refreshLease: ChannelLease | null = null;

onMounted(() => {
    refreshLease = acquirePrivateChannel(PRICE_REFRESH_CHANNEL).listen(
        '.AiPriceRefreshStateChanged',
        (event: PriceRefreshPayload) => handleRefreshState(event),
    );
});

onUnmounted(() => {
    refreshLease?.release();
    refreshLease = null;
});

function startEdit(price: PriceRow) {
    editing.value = { ...price };
    editPoolId.value =
        price.free_usage_pool_id === null
            ? 'none'
            : String(price.free_usage_pool_id);
    editSupportsReasoning.value =
        price.supports_reasoning === null
            ? 'unknown'
            : price.supports_reasoning
              ? 'yes'
              : 'no';
    editReasoningLevels.value = [...(price.reasoning_levels ?? [])];
    editRateLimits.value = price.rate_limits.map((limit) => ({
        metric: limit.metric,
        period: limit.period,
        limit_value: limit.limit_value,
    }));
    editAutomaticUpdates.value = price.automatic_updates_enabled;
    editAutomaticUpdatesTouched.value = false;
}

function cancelEdit() {
    editing.value = null;
}

async function destroy(price: PriceRow): Promise<void> {
    const confirmed = await confirm({
        title: `Remove pricing for ${price.provider}/${price.model}?`,
        confirmLabel: 'Remove',
        destructive: true,
    });

    if (!confirmed) {
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

const ALL_PROVIDERS = 'all';

const providerFilter = ref(ALL_PROVIDERS);
const modelSearch = ref('');

const providerOptions = computed(() =>
    [...new Set(props.prices.map((price) => price.provider))].sort(),
);

const visiblePrices = computed(() => {
    const needle = modelSearch.value.trim().toLowerCase();

    return props.prices.filter(
        (price) =>
            (providerFilter.value === ALL_PROVIDERS ||
                price.provider === providerFilter.value) &&
            (needle === '' || price.model.toLowerCase().includes(needle)),
    );
});

const selectedIds = ref<number[]>([]);

// A bulk action must never reach a row the admin can't see, so narrowing the
// filter (or a reload dropping rows) prunes the selection to visible rows.
watch(visiblePrices, (visible) => {
    const visibleIds = new Set(visible.map((price) => price.id));
    selectedIds.value = selectedIds.value.filter((id) => visibleIds.has(id));
});

const selectedPrices = computed(() =>
    visiblePrices.value.filter((price) => selectedIds.value.includes(price.id)),
);

const selectAllState = computed<boolean | 'indeterminate'>(() => {
    if (selectedPrices.value.length === 0) {
        return false;
    }

    return selectedPrices.value.length === visiblePrices.value.length
        ? true
        : 'indeterminate';
});

function toggleAllVisible(): void {
    selectedIds.value =
        selectAllState.value === true
            ? []
            : visiblePrices.value.map((price) => price.id);
}

function toggleRow(id: number, selected: boolean | 'indeterminate'): void {
    selectedIds.value =
        selected === true
            ? [...selectedIds.value, id]
            : selectedIds.value.filter((selectedId) => selectedId !== id);
}

function clearSelection(): void {
    selectedIds.value = [];
}

const showBulkEditDialog = ref(false);
const showBulkDeleteDialog = ref(false);

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
        <div class="flex flex-wrap items-end justify-between gap-3">
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
            <div class="flex flex-wrap items-center gap-2">
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
                <AddFromCatalogDialog :providers="catalog_providers" />
                <CreatePriceDialog
                    :pools="pools"
                    :rate-limit-metrics="rate_limit_metrics"
                    :rate-limit-periods="rate_limit_periods"
                    :catalog-providers="catalog_providers"
                />
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
                            v-bind="AiFreeUsagePoolController.store.form()"
                            class="space-y-4"
                            v-slot="{ errors, processing }"
                            @success="showPoolCreateDialog = false"
                        >
                            <PoolFormFields id-prefix="pool" :errors="errors" />
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
                            :data-pool-row="pool.id"
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
                                        data-pool-delete
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
                    <PoolFormFields
                        :key="editingPool.id"
                        id-prefix="edit_pool"
                        :pool="editingPool"
                        :errors="errors"
                    />
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
                class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3"
            >
                <span
                    class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                >
                    Configured models
                </span>
                <div class="flex flex-wrap items-center gap-2">
                    <Select v-model="providerFilter">
                        <SelectTrigger
                            class="h-8 w-40 text-[12.5px]"
                            aria-label="Filter by provider"
                            data-price-filter-provider
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                :value="ALL_PROVIDERS"
                                aria-label="All providers"
                            >
                                All providers
                            </SelectItem>
                            <SelectItem
                                v-for="provider in providerOptions"
                                :key="provider"
                                :value="provider"
                                :aria-label="provider"
                            >
                                {{ provider }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <Input
                        v-model="modelSearch"
                        type="search"
                        placeholder="Search models"
                        aria-label="Search models"
                        class="h-8 w-48 text-[12.5px]"
                        data-price-filter-search
                    />
                </div>
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
            <div
                v-if="selectedPrices.length > 0"
                class="flex flex-wrap items-center gap-2 border-b border-border bg-bg-elev px-4 py-2 text-[12.5px]"
                data-bulk-bar
            >
                <span class="font-medium" data-bulk-count>
                    {{ selectedPrices.length }} selected
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    class="h-7 px-2.5 text-xs"
                    data-bulk-edit
                    @click="showBulkEditDialog = true"
                >
                    Edit selected
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    class="h-7 px-2.5 text-xs text-destructive hover:text-destructive"
                    data-bulk-delete
                    @click="showBulkDeleteDialog = true"
                >
                    <Trash2 class="size-3.5" />
                    Delete selected
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-7 px-2.5 text-xs"
                    data-bulk-clear
                    @click="clearSelection"
                >
                    Clear
                </Button>
            </div>
            <div class="overflow-x-auto">
                <table
                    class="w-full border-collapse text-[13px]"
                    data-prices-table
                >
                    <thead>
                        <tr>
                            <th
                                class="w-8 border-b border-border bg-card py-2 pr-1 pl-3 text-left"
                            >
                                <Checkbox
                                    :model-value="selectAllState"
                                    :disabled="visiblePrices.length === 0"
                                    aria-label="Select all shown models"
                                    data-select-all
                                    @update:model-value="toggleAllVisible"
                                >
                                    <Minus
                                        v-if="
                                            selectAllState === 'indeterminate'
                                        "
                                        class="size-3.5"
                                    />
                                    <Check v-else class="size-3.5" />
                                </Checkbox>
                            </th>
                            <th
                                v-for="h in [
                                    'Model',
                                    'Provider',
                                    'Input',
                                    'Output',
                                    'Cache R',
                                    'Cache W',
                                    'Reasoning',
                                    'Source',
                                    'Synced',
                                    'Verified',
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
                            v-for="price in visiblePrices"
                            :key="price.id"
                            class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                            :class="
                                showBatch && !hasBatch(price)
                                    ? 'opacity-50'
                                    : ''
                            "
                            :data-price-row="price.model"
                        >
                            <td class="py-2.5 pr-1 pl-3">
                                <Checkbox
                                    :model-value="
                                        selectedIds.includes(price.id)
                                    "
                                    :aria-label="`Select ${price.provider} / ${price.model}`"
                                    data-select-row
                                    @update:model-value="
                                        (selected) =>
                                            toggleRow(price.id, selected)
                                    "
                                />
                            </td>
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
                            <td class="px-3 py-2.5">
                                <div class="flex flex-col gap-1">
                                    <template v-if="price.pricing_source">
                                        <a
                                            v-if="
                                                safeSourceHref(
                                                    price.pricing_source_url,
                                                )
                                            "
                                            :href="
                                                safeSourceHref(
                                                    price.pricing_source_url,
                                                )!
                                            "
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="w-fit"
                                        >
                                            <Pill
                                                :variant="
                                                    sourceVariant(
                                                        price.pricing_source,
                                                    )
                                                "
                                                data-price-source
                                            >
                                                {{
                                                    sourceLabel(
                                                        price.pricing_source,
                                                    )
                                                }}
                                            </Pill>
                                        </a>
                                        <Pill
                                            v-else
                                            :variant="
                                                sourceVariant(
                                                    price.pricing_source,
                                                )
                                            "
                                            data-price-source
                                        >
                                            {{
                                                sourceLabel(
                                                    price.pricing_source,
                                                )
                                            }}
                                        </Pill>
                                    </template>
                                    <span v-else class="text-fg-subtle">—</span>
                                    <span class="text-[11px] text-fg-subtle">
                                        {{
                                            price.automatic_updates_enabled
                                                ? 'Auto-updates on'
                                                : 'Locked'
                                        }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-3 py-2.5 text-muted-foreground">
                                <TimeStamp
                                    :iso="price.pricing_synced_at"
                                    mode="smart"
                                />
                            </td>
                            <td class="px-3 py-2.5 text-muted-foreground">
                                <TimeStamp
                                    :iso="price.pricing_verified_at"
                                    mode="smart"
                                />
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
                                        data-price-delete
                                        @click="destroy(price)"
                                    >
                                        <Trash2 class="size-3.5" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="prices.length === 0">
                            <td
                                colspan="12"
                                class="px-3 py-8 text-center text-sm text-fg-subtle"
                            >
                                No models priced yet. Click "Add model price".
                            </td>
                        </tr>
                        <tr v-else-if="visiblePrices.length === 0">
                            <td
                                colspan="12"
                                class="px-3 py-8 text-center text-sm text-fg-subtle"
                            >
                                No models match the filter.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <BulkEditPricesDialog
            v-model:open="showBulkEditDialog"
            :ids="selectedPrices.map((price) => price.id)"
            :pools="pools"
            :rate-limit-metrics="rate_limit_metrics"
            :rate-limit-periods="rate_limit_periods"
            @saved="clearSelection"
        />
        <BulkDeletePricesDialog
            v-model:open="showBulkDeleteDialog"
            :prices="selectedPrices"
            @deleted="clearSelection"
        />

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
                        <div
                            class="col-span-2 space-y-2"
                            data-search-unit-field
                        >
                            <Label for="edit_search_unit"
                                >Search units ($/1k)</Label
                            >
                            <Input
                                id="edit_search_unit"
                                name="search_unit_per_k"
                                type="number"
                                step="0.0001"
                                min="0"
                                :default-value="editing.search_unit_per_k"
                            />
                            <p class="text-[11px] text-fg-subtle">
                                Rerank models only — price per 1,000 searches.
                            </p>
                            <InputError :message="errors.search_unit_per_k" />
                        </div>
                        <div class="col-span-2 space-y-2">
                            <Label for="edit_free_usage_pool_id"
                                >Free usage pool</Label
                            >
                            <input
                                type="hidden"
                                name="free_usage_pool_id"
                                :value="editPoolId === 'none' ? '' : editPoolId"
                            />
                            <Select
                                id="edit_free_usage_pool_id"
                                v-model="editPoolId"
                            >
                                <SelectTrigger class="h-9 w-full text-sm">
                                    <SelectValue placeholder="No pool" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        No pool
                                    </SelectItem>
                                    <SelectItem
                                        v-for="pool in pools"
                                        :key="pool.id"
                                        :value="String(pool.id)"
                                    >
                                        {{ pool.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="errors.free_usage_pool_id" />
                        </div>
                        <div class="col-span-2 space-y-2">
                            <Label for="edit_supports_reasoning"
                                >Supports reasoning</Label
                            >
                            <input
                                type="hidden"
                                name="supports_reasoning"
                                :value="editSupportsReasoning"
                            />
                            <Select
                                id="edit_supports_reasoning"
                                v-model="editSupportsReasoning"
                            >
                                <SelectTrigger
                                    class="h-9 w-full text-sm"
                                    data-price-supports-reasoning
                                >
                                    <SelectValue placeholder="Unknown" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="yes">Yes</SelectItem>
                                    <SelectItem value="no">No</SelectItem>
                                    <SelectItem value="unknown">
                                        Unknown
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="errors.supports_reasoning" />
                            <Label>Accepted reasoning levels</Label>
                            <div class="flex flex-wrap gap-x-4 gap-y-2">
                                <label
                                    v-for="level in REASONING_LEVEL_OPTIONS"
                                    :key="level"
                                    class="flex cursor-pointer items-center gap-2 text-[13px]"
                                >
                                    <input
                                        type="checkbox"
                                        :value="level"
                                        v-model="editReasoningLevels"
                                        :data-price-reasoning-level="level"
                                        class="size-4 rounded border-border accent-accent"
                                    />
                                    {{ level }}
                                </label>
                            </div>
                            <!-- Blank placeholder so an all-unchecked group still submits. -->
                            <input
                                type="hidden"
                                name="reasoning_levels[]"
                                value=""
                            />
                            <input
                                v-for="level in editReasoningLevels"
                                :key="level"
                                type="hidden"
                                name="reasoning_levels[]"
                                :value="level"
                            />
                            <p class="text-[11px] text-fg-subtle">
                                Filled by the pricing feeds. Feed syncs
                                overwrite it unless automatic updates are off.
                            </p>
                            <InputError :message="errors.reasoning_levels" />
                        </div>
                        <div class="col-span-2 space-y-2">
                            <Label>Automatic pricing updates</Label>
                            <input
                                v-if="editAutomaticUpdatesTouched"
                                type="hidden"
                                name="automatic_updates_enabled"
                                :value="editAutomaticUpdates ? '1' : '0'"
                            />
                            <Toggle
                                :model-value="editAutomaticUpdates"
                                role="switch"
                                aria-label="Automatic pricing updates"
                                :aria-checked="editAutomaticUpdates"
                                :label="
                                    editAutomaticUpdates
                                        ? 'On — kept in sync online'
                                        : 'Off — locked to manual price'
                                "
                                @update:model-value="
                                    onEditAutomaticUpdatesChange
                                "
                            />
                            <p class="text-[11px] text-fg-subtle">
                                Editing any rate locks this row so an online
                                refresh won't overwrite your price. Flip this
                                toggle to override: on keeps automatic updates,
                                off forces it locked.
                            </p>
                            <InputError
                                :message="errors.automatic_updates_enabled"
                            />
                        </div>
                        <RateLimitEditor
                            v-model="editRateLimits"
                            :metrics="rate_limit_metrics"
                            :periods="rate_limit_periods"
                            :errors="errors"
                        />
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
