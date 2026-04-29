<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight, Download, Sparkles } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AiModelPriceController from '@/actions/App/Http/Controllers/Admin/AiModelPriceController';
import AiUsageController from '@/actions/App/Http/Controllers/Admin/AiUsageController';
import { InitialsAvatar, Pill, StatCard } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
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
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { QueryParams } from '@/wayfinder';

interface Totals {
    total_invocations: number;
    total_tool_calls: number;
    total_tokens: number;
    total_cost: string;
}

interface AggregateRow {
    key: string | null;
    invocations: number;
    total_tokens: number;
    total_cost: string;
}

interface RecentRow {
    id: number;
    created_at: string;
    provider: string | null;
    model: string | null;
    prompt_tokens: number;
    completion_tokens: number;
    tool_calls_count: number;
    total_tokens: number;
    cost: string;
    conversation_id: string | null;
    status: string;
    user_name: string | null;
}

interface PricedModel {
    provider: string;
    model: string;
    input_per_mtok: string;
    output_per_mtok: string;
    cache_read_per_mtok: string;
    cache_write_per_mtok: string;
    reasoning_per_mtok: string;
}

interface ScenarioRates {
    input: number;
    output: number;
    cache_read: number;
    cache_write: number;
    reasoning: number;
}

interface BreakdownLine {
    label: string;
    tokens: number;
    rate: number;
    cost: number;
}

interface InvocationDetail {
    record: {
        id: number;
        invocation_id: string;
        agent_class: string | null;
        provider: string | null;
        model: string | null;
        prompt_tokens: number;
        completion_tokens: number;
        cache_read_input_tokens: number;
        cache_write_input_tokens: number;
        reasoning_tokens: number;
        tool_calls_count: number;
        price_source: string | null;
        conversation_id: string | null;
        status: string;
        created_at: string | null;
    };
    user: { id: number; name: string } | null;
    tools: Array<{
        id: number;
        tool_class: string;
        tool_invocation_id: string | null;
        status: string;
        created_at: string | null;
    }>;
    rates: {
        source: 'snapshot' | 'catalog' | 'unpriced';
        input_per_mtok: number;
        output_per_mtok: number;
        cache_read_per_mtok: number;
        cache_write_per_mtok: number;
        reasoning_per_mtok: number;
    };
    breakdown: BreakdownLine[];
    total_cost: number;
    scenario_breakdown: BreakdownLine[] | null;
    scenario_total_cost: number | null;
}

type WindowKey = 'today' | '24h' | '7d' | '30d';

const props = defineProps<{
    window: WindowKey;
    windows: WindowKey[];
    totals: Totals;
    by_model: AggregateRow[];
    by_provider: AggregateRow[];
    recent: RecentRow[];
    priced_models: PricedModel[];
    scenario: ScenarioRates | null;
    scenario_totals?: Totals;
    scenario_by_model?: AggregateRow[];
    scenario_by_provider?: AggregateRow[];
    scenario_recent?: RecentRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'AI Usage', href: AiUsageController.index.url() },
        ],
    },
});

const scenarioActive = computed(() => props.scenario !== null);
const panelOpen = ref(scenarioActive.value);

const form = ref({
    input: props.scenario?.input ?? 0,
    output: props.scenario?.output ?? 0,
    cache_read: props.scenario?.cache_read ?? 0,
    cache_write: props.scenario?.cache_write ?? 0,
    reasoning: props.scenario?.reasoning ?? 0,
});

const selectedLoadKey = ref<string>('');

const aggregatedScenarioByModel = computed(() =>
    indexByKey(props.scenario_by_model ?? []),
);
const aggregatedScenarioByProvider = computed(() =>
    indexByKey(props.scenario_by_provider ?? []),
);
const indexedScenarioRecent = computed(() =>
    Object.fromEntries(
        (props.scenario_recent ?? []).map((row) => [row.id, row.cost] as const),
    ),
);

function indexByKey(rows: AggregateRow[]): Record<string, string> {
    return Object.fromEntries(
        rows.map((row) => [row.key ?? '__null__', row.total_cost] as const),
    );
}

function setWindow(value: string) {
    router.visit(
        AiUsageController.index.url({
            query: buildQuery({ window: value }),
        }),
        { preserveScroll: true, preserveState: true },
    );
}

const exportUrl = computed(() =>
    AiUsageController.exportMethod.url({
        query: buildQuery({ window: props.window }),
    }),
);

function buildQuery(extra: Record<string, string>): QueryParams {
    const query: QueryParams = { ...extra };

    if (props.scenario) {
        query.scenario = { ...props.scenario };
    }

    return query;
}

function loadFromModel(key: string) {
    selectedLoadKey.value = key;

    if (!key) {
        return;
    }

    const [provider, model] = key.split('|');
    const priced = props.priced_models.find(
        (p) => p.provider === provider && p.model === model,
    );

    if (!priced) {
        return;
    }

    form.value = {
        input: parseFloat(priced.input_per_mtok),
        output: parseFloat(priced.output_per_mtok),
        cache_read: parseFloat(priced.cache_read_per_mtok),
        cache_write: parseFloat(priced.cache_write_per_mtok),
        reasoning: parseFloat(priced.reasoning_per_mtok),
    };
}

function applyScenario() {
    router.visit(
        AiUsageController.index.url({
            query: {
                window: props.window,
                scenario: {
                    input: form.value.input,
                    output: form.value.output,
                    cache_read: form.value.cache_read,
                    cache_write: form.value.cache_write,
                    reasoning: form.value.reasoning,
                },
            },
        }),
        { preserveScroll: true },
    );
}

function clearScenario() {
    router.visit(
        AiUsageController.index.url({ query: { window: props.window } }),
        { preserveScroll: true },
    );
}

const detail = ref<InvocationDetail | null>(null);
const detailLoading = ref(false);
const detailError = ref<string | null>(null);
const assignKey = ref<string>('');
const assigning = ref(false);

async function openDetail(row: RecentRow) {
    detail.value = null;
    detailError.value = null;
    detailLoading.value = true;
    assignKey.value = '';

    try {
        const url = AiUsageController.show.url(
            { aiUsageRecord: row.id },
            props.scenario ? { query: { scenario: { ...props.scenario } } } : {},
        );
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        detail.value = (await response.json()) as InvocationDetail;
    } catch (error) {
        detailError.value =
            error instanceof Error ? error.message : 'Failed to load detail';
    } finally {
        detailLoading.value = false;
    }
}

function closeDetail() {
    detail.value = null;
    detailError.value = null;
    assignKey.value = '';
}

function assignPrice() {
    if (!detail.value || !assignKey.value) {
        return;
    }

    const [provider, model] = assignKey.value.split('|');
    const recordId = detail.value.record.id;

    assigning.value = true;

    router.post(
        AiUsageController.assignPrice.url({ aiUsageRecord: recordId }),
        { provider, model },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                assigning.value = false;
                openDetail({ id: recordId } as RecentRow);
            },
            onError: () => {
                assigning.value = false;
            },
        },
    );
}

function rateSourceLabel(source: 'snapshot' | 'catalog' | 'unpriced'): string {
    return source === 'snapshot'
        ? 'Snapshot at call time'
        : source === 'catalog'
          ? 'Live catalog price'
          : 'Unpriced';
}

function rateSourceVariant(
    source: 'snapshot' | 'catalog' | 'unpriced',
): 'ok' | 'info' | 'warn' {
    return source === 'snapshot'
        ? 'ok'
        : source === 'catalog'
          ? 'info'
          : 'warn';
}

function formatRate(value: number): string {
    return `$${value.toFixed(4)}`;
}

function formatCost(value: string | number): string {
    const n = typeof value === 'string' ? parseFloat(value) : value;

    if (n < 0.01 && n > 0) {
        return `$${n.toFixed(5)}`;
    }

    return `$${n.toFixed(2)}`;
}

function costDelta(actual: string, projected: string | undefined): string {
    if (projected === undefined) {
        return '';
    }

    const a = parseFloat(actual);
    const p = parseFloat(projected);
    const diff = p - a;
    const sign = diff > 0 ? '+' : '';

    return `${sign}${formatCost(Math.abs(diff))}${diff > 0 ? ' more' : diff < 0 ? ' less' : ''}`;
}

function formatNumber(value: number | string): string {
    const n = typeof value === 'string' ? parseFloat(value) : value;

    return n.toLocaleString('en-US');
}

function formatTimestamp(value: string): string {
    // The ledger SELECTs created_at as a raw timestamp without timezone
    // info, so JS would otherwise interpret it as local time. Append 'Z'
    // when the string has no TZ designator so it is parsed as UTC and the
    // toLocale* calls below convert to the viewer's local timezone.
    const hasTz = /Z$|[+-]\d{2}:?\d{2}$/.test(value);
    const d = new Date(hasTz ? value : `${value.replace(' ', 'T')}Z`);
    const today = new Date();
    const sameDay =
        d.getFullYear() === today.getFullYear() &&
        d.getMonth() === today.getMonth() &&
        d.getDate() === today.getDate();
    const time = d.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });

    if (sameDay) {
        return time;
    }

    const date = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

    return `${date} ${time}`;
}
</script>

<template>
    <Head title="AI Usage" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero -->
        <div class="flex items-end justify-between gap-3">
            <div>
                <div class="mb-1.5 text-[13px] text-muted-foreground">
                    Admin <span class="text-fg-subtle">/</span> AI usage
                </div>
                <h1
                    class="text-[22px] leading-tight font-semibold tracking-tight"
                >
                    AI usage
                </h1>
                <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                    Per-call ledger with token counts, latency, and cost.
                    Pricing pulled from
                    <a
                        :href="AiModelPriceController.index.url()"
                        class="underline hover:text-foreground"
                        >model prices</a
                    >.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <div
                    class="flex items-center gap-0.5 rounded-md border border-border bg-bg-elev p-0.5"
                >
                    <button
                        v-for="opt in props.windows"
                        :key="opt"
                        type="button"
                        :class="
                            cn(
                                'inline-flex h-6 items-center rounded px-2 text-xs font-medium transition-colors',
                                props.window === opt
                                    ? 'bg-accent text-accent-foreground'
                                    : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                            )
                        "
                        @click="setWindow(opt)"
                    >
                        {{ opt === 'today' ? 'Today' : opt }}
                    </button>
                </div>
                <a
                    :href="exportUrl"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md border border-border bg-card px-2 text-xs font-medium text-foreground transition-colors hover:bg-bg-hover"
                >
                    <Download class="size-3.5" />Export CSV
                </a>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <StatCard
                label="Spend"
                :value="formatCost(totals.total_cost)"
                :hint="
                    scenarioActive && scenario_totals
                        ? `${formatCost(scenario_totals.total_cost)} projected · ${costDelta(totals.total_cost, scenario_totals.total_cost)}`
                        : `${props.window} window`
                "
            />
            <StatCard
                label="Invocations"
                :value="formatNumber(totals.total_invocations)"
                :hint="`${formatNumber(totals.total_tool_calls)} tool calls`"
            />
            <StatCard
                label="Total tokens"
                :value="formatNumber(totals.total_tokens)"
                hint="prompt + completion"
            />
            <StatCard
                label="Tool calls"
                :value="formatNumber(totals.total_tool_calls)"
                hint="across all invocations"
            />
        </div>

        <!-- Scenario panel -->
        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <button
                type="button"
                class="flex w-full items-center justify-between border-b border-border px-4 py-3 hover:bg-bg-hover"
                @click="panelOpen = !panelOpen"
            >
                <span class="flex items-center gap-2">
                    <ChevronDown v-if="panelOpen" class="size-4" />
                    <ChevronRight v-else class="size-4" />
                    <Sparkles class="size-3.5 text-accent" />
                    <span
                        class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                        >What-if scenario</span
                    >
                    <Pill v-if="scenarioActive" variant="info">active</Pill>
                </span>
                <span class="text-xs text-muted-foreground"
                    >Recompute against hypothetical rates</span
                >
            </button>
            <div v-if="panelOpen" class="space-y-4 p-4">
                <div class="space-y-2">
                    <Label class="text-xs"
                        >Load rates from existing model</Label
                    >
                    <Select
                        :model-value="selectedLoadKey"
                        @update:model-value="
                            (value) =>
                                loadFromModel(
                                    typeof value === 'string' ? value : '',
                                )
                        "
                    >
                        <SelectTrigger class="h-8 text-sm">
                            <SelectValue
                                placeholder="Pick a priced model to copy its rates…"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="model in priced_models"
                                :key="`${model.provider}|${model.model}`"
                                :value="`${model.provider}|${model.model}`"
                            >
                                {{ model.provider }} / {{ model.model }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-3 md:grid-cols-5">
                    <div
                        v-for="field in [
                            ['input', 'Input'],
                            ['output', 'Output'],
                            ['cache_read', 'Cache read'],
                            ['cache_write', 'Cache write'],
                            ['reasoning', 'Reasoning'],
                        ] as const"
                        :key="field[0]"
                        class="space-y-1"
                    >
                        <Label :for="`rate_${field[0]}`" class="text-xs">
                            {{ field[1] }}
                        </Label>
                        <Input
                            :id="`rate_${field[0]}`"
                            type="number"
                            step="0.0001"
                            min="0"
                            class="h-8 font-mono text-xs"
                            v-model.number="
                                form[
                                    field[0] as
                                        | 'input'
                                        | 'output'
                                        | 'cache_read'
                                        | 'cache_write'
                                        | 'reasoning'
                                ]
                            "
                        />
                    </div>
                </div>

                <div class="flex gap-2">
                    <Button
                        size="sm"
                        class="h-7 text-xs"
                        @click="applyScenario"
                    >
                        Apply
                    </Button>
                    <Button
                        v-if="scenarioActive"
                        size="sm"
                        variant="outline"
                        class="h-7 text-xs"
                        @click="clearScenario"
                    >
                        Clear
                    </Button>
                </div>
            </div>
        </div>

        <!-- By model + by provider -->
        <div class="grid gap-4 lg:grid-cols-2">
            <div
                class="overflow-hidden rounded-xl border border-border bg-card"
            >
                <div
                    class="border-b border-border px-4 py-3 text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                >
                    By model
                </div>
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th
                                class="border-b border-border px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Model
                            </th>
                            <th
                                class="border-b border-border px-3 py-2 text-right text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Calls
                            </th>
                            <th
                                class="border-b border-border px-3 py-2 text-right text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Cost
                            </th>
                            <th
                                v-if="scenarioActive"
                                class="border-b border-border px-3 py-2 text-right text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Projected
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in by_model"
                            :key="row.key ?? 'null'"
                            class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                        >
                            <td class="font-mono-tabular px-3 py-2 text-[12px]">
                                {{ row.key ?? '—' }}
                            </td>
                            <td class="font-mono-tabular px-3 py-2 text-right">
                                {{ formatNumber(row.invocations) }}
                            </td>
                            <td class="font-mono-tabular px-3 py-2 text-right">
                                {{ formatCost(row.total_cost) }}
                            </td>
                            <td
                                v-if="scenarioActive"
                                class="font-mono-tabular px-3 py-2 text-right text-accent"
                            >
                                {{
                                    formatCost(
                                        aggregatedScenarioByModel[
                                            row.key ?? '__null__'
                                        ] ?? '0',
                                    )
                                }}
                            </td>
                        </tr>
                        <tr v-if="by_model.length === 0">
                            <td
                                :colspan="scenarioActive ? 4 : 3"
                                class="px-3 py-6 text-center text-sm text-fg-subtle"
                            >
                                No data in this window.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                class="overflow-hidden rounded-xl border border-border bg-card"
            >
                <div
                    class="border-b border-border px-4 py-3 text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                >
                    By provider
                </div>
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th
                                class="border-b border-border px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Provider
                            </th>
                            <th
                                class="border-b border-border px-3 py-2 text-right text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Calls
                            </th>
                            <th
                                class="border-b border-border px-3 py-2 text-right text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Cost
                            </th>
                            <th
                                v-if="scenarioActive"
                                class="border-b border-border px-3 py-2 text-right text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Projected
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in by_provider"
                            :key="row.key ?? 'null'"
                            class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                        >
                            <td class="font-mono-tabular px-3 py-2 text-[12px]">
                                {{ row.key ?? '—' }}
                            </td>
                            <td class="font-mono-tabular px-3 py-2 text-right">
                                {{ formatNumber(row.invocations) }}
                            </td>
                            <td class="font-mono-tabular px-3 py-2 text-right">
                                {{ formatCost(row.total_cost) }}
                            </td>
                            <td
                                v-if="scenarioActive"
                                class="font-mono-tabular px-3 py-2 text-right text-accent"
                            >
                                {{
                                    formatCost(
                                        aggregatedScenarioByProvider[
                                            row.key ?? '__null__'
                                        ] ?? '0',
                                    )
                                }}
                            </td>
                        </tr>
                        <tr v-if="by_provider.length === 0">
                            <td
                                :colspan="scenarioActive ? 4 : 3"
                                class="px-3 py-6 text-center text-sm text-fg-subtle"
                            >
                                No data in this window.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent calls ledger -->
        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <div
                class="border-b border-border px-4 py-3 text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
            >
                Recent invocations
            </div>
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            v-for="h in [
                                'When',
                                'User',
                                'Model',
                                'Tokens',
                                'Tools',
                                'Cost',
                                ...(scenarioActive ? ['Projected'] : []),
                                'Status',
                            ]"
                            :key="h"
                            class="border-b border-border px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            {{ h }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in recent"
                        :key="row.id"
                        class="cursor-pointer border-b border-border last:border-b-0 hover:bg-bg-hover"
                        @click="openDetail(row)"
                    >
                        <td
                            class="font-mono-tabular px-3 py-2 text-[11.5px] whitespace-nowrap text-fg-subtle"
                        >
                            {{ formatTimestamp(row.created_at) }}
                        </td>
                        <td class="px-3 py-2">
                            <span class="flex items-center gap-2">
                                <InitialsAvatar
                                    :name="row.user_name ?? 'system'"
                                    :size="20"
                                />
                                <span>{{ row.user_name ?? '—' }}</span>
                            </span>
                        </td>
                        <td class="font-mono-tabular px-3 py-2 text-[12px]">
                            {{ row.model ?? '—' }}
                        </td>
                        <td class="font-mono-tabular px-3 py-2 text-right">
                            {{ formatNumber(row.total_tokens) }}
                        </td>
                        <td class="font-mono-tabular px-3 py-2 text-right">
                            {{ row.tool_calls_count }}
                        </td>
                        <td class="font-mono-tabular px-3 py-2 text-right">
                            {{ formatCost(row.cost) }}
                        </td>
                        <td
                            v-if="scenarioActive"
                            class="font-mono-tabular px-3 py-2 text-right text-accent"
                        >
                            {{
                                formatCost(indexedScenarioRecent[row.id] ?? '0')
                            }}
                        </td>
                        <td class="px-3 py-2">
                            <Pill
                                :variant="
                                    row.status === 'success' ? 'ok' : 'danger'
                                "
                                dot
                            >
                                {{ row.status }}
                            </Pill>
                        </td>
                    </tr>
                    <tr v-if="recent.length === 0">
                        <td
                            :colspan="scenarioActive ? 8 : 7"
                            class="px-3 py-6 text-center text-sm text-fg-subtle"
                        >
                            No invocations in this window.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Detail modal -->
        <Dialog
            :open="detail !== null || detailLoading || detailError !== null"
            @update:open="(v) => !v && closeDetail()"
        >
            <DialogContent class="max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Invocation detail</DialogTitle>
                </DialogHeader>

                <div
                    v-if="detailLoading"
                    class="px-2 py-8 text-center text-sm text-muted-foreground"
                >
                    Loading…
                </div>
                <div
                    v-else-if="detailError"
                    class="px-2 py-8 text-center text-sm text-destructive"
                >
                    {{ detailError }}
                </div>
                <div v-else-if="detail" class="space-y-5">
                    <!-- Meta header -->
                    <div class="grid gap-3 md:grid-cols-3">
                        <div>
                            <div
                                class="text-[11px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                When
                            </div>
                            <div class="font-mono-tabular text-[12px]">
                                {{
                                    detail.record.created_at
                                        ? formatTimestamp(
                                              detail.record.created_at,
                                          )
                                        : '—'
                                }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-[11px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                User
                            </div>
                            <div class="text-[13px]">
                                {{ detail.user?.name ?? '—' }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-[11px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Status
                            </div>
                            <Pill
                                :variant="
                                    detail.record.status === 'success'
                                        ? 'ok'
                                        : 'danger'
                                "
                                dot
                            >
                                {{ detail.record.status }}
                            </Pill>
                        </div>
                        <div>
                            <div
                                class="text-[11px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Provider / Model
                            </div>
                            <div class="font-mono-tabular text-[12px]">
                                {{ detail.record.provider ?? '—' }} /
                                {{ detail.record.model ?? '—' }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-[11px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Agent
                            </div>
                            <div
                                class="font-mono-tabular text-[12px] break-all"
                            >
                                {{
                                    detail.record.agent_class
                                        ?.split('\\')
                                        .pop() ?? '—'
                                }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-[11px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Invocation
                            </div>
                            <div
                                class="font-mono-tabular text-[12px] break-all"
                            >
                                {{ detail.record.invocation_id }}
                            </div>
                        </div>
                    </div>

                    <!-- Pricing source banner -->
                    <div
                        class="flex items-center justify-between rounded-md border border-border bg-bg-elev px-3 py-2"
                    >
                        <div class="flex items-center gap-2 text-[12px]">
                            <span class="text-muted-foreground">Pricing:</span>
                            <Pill
                                :variant="rateSourceVariant(detail.rates.source)"
                            >
                                {{ rateSourceLabel(detail.rates.source) }}
                            </Pill>
                            <span
                                v-if="detail.record.price_source === 'assigned'"
                                class="text-muted-foreground"
                                >(retroactively assigned)</span
                            >
                        </div>
                        <div
                            v-if="detail.rates.source === 'unpriced'"
                            class="text-[12px] text-warning"
                        >
                            No price available — assign one below to recompute
                            cost.
                        </div>
                    </div>

                    <!-- Cost breakdown -->
                    <div
                        class="overflow-hidden rounded-md border border-border"
                    >
                        <table
                            class="w-full border-collapse text-[12px]"
                        >
                            <thead>
                                <tr class="bg-bg-elev">
                                    <th
                                        class="px-3 py-2 text-left font-medium text-muted-foreground"
                                    >
                                        Component
                                    </th>
                                    <th
                                        class="px-3 py-2 text-right font-medium text-muted-foreground"
                                    >
                                        Tokens
                                    </th>
                                    <th
                                        class="px-3 py-2 text-right font-medium text-muted-foreground"
                                    >
                                        Rate / 1M
                                    </th>
                                    <th
                                        class="px-3 py-2 text-right font-medium text-muted-foreground"
                                    >
                                        Cost
                                    </th>
                                    <th
                                        v-if="detail.scenario_breakdown"
                                        class="px-3 py-2 text-right font-medium text-muted-foreground"
                                    >
                                        Scenario
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(line, idx) in detail.breakdown"
                                    :key="line.label"
                                    class="border-t border-border"
                                >
                                    <td class="px-3 py-2">{{ line.label }}</td>
                                    <td
                                        class="font-mono-tabular px-3 py-2 text-right"
                                    >
                                        {{ formatNumber(line.tokens) }}
                                    </td>
                                    <td
                                        class="font-mono-tabular px-3 py-2 text-right"
                                    >
                                        {{ formatRate(line.rate) }}
                                    </td>
                                    <td
                                        class="font-mono-tabular px-3 py-2 text-right"
                                    >
                                        {{ formatCost(line.cost) }}
                                    </td>
                                    <td
                                        v-if="detail.scenario_breakdown"
                                        class="font-mono-tabular px-3 py-2 text-right text-accent"
                                    >
                                        {{
                                            formatCost(
                                                detail.scenario_breakdown[idx]
                                                    .cost,
                                            )
                                        }}
                                    </td>
                                </tr>
                                <tr
                                    class="border-t-2 border-border bg-bg-elev font-semibold"
                                >
                                    <td class="px-3 py-2" colspan="3">Total</td>
                                    <td
                                        class="font-mono-tabular px-3 py-2 text-right"
                                    >
                                        {{ formatCost(detail.total_cost) }}
                                    </td>
                                    <td
                                        v-if="detail.scenario_total_cost !== null"
                                        class="font-mono-tabular px-3 py-2 text-right text-accent"
                                    >
                                        {{
                                            formatCost(
                                                detail.scenario_total_cost,
                                            )
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Tools -->
                    <div v-if="detail.tools.length > 0">
                        <div
                            class="mb-2 text-[11px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                        >
                            Tools used ({{ detail.tools.length }})
                        </div>
                        <ul class="space-y-1">
                            <li
                                v-for="tool in detail.tools"
                                :key="tool.id"
                                class="flex items-center justify-between rounded border border-border bg-card px-3 py-1.5 text-[12px]"
                            >
                                <span
                                    class="font-mono-tabular truncate"
                                    :title="tool.tool_class"
                                >
                                    {{ tool.tool_class.split('\\').pop() }}
                                </span>
                                <Pill
                                    :variant="
                                        tool.status === 'success'
                                            ? 'ok'
                                            : 'danger'
                                    "
                                    dot
                                >
                                    {{ tool.status }}
                                </Pill>
                            </li>
                        </ul>
                    </div>

                    <!-- Retroactive price assignment -->
                    <div
                        v-if="
                            detail.rates.source === 'unpriced' ||
                            detail.record.price_source !== 'assigned'
                        "
                        class="rounded-md border border-border bg-card p-3"
                    >
                        <div class="mb-2 flex items-center justify-between">
                            <Label
                                class="text-[11px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                            >
                                {{
                                    detail.rates.source === 'unpriced'
                                        ? 'Assign price from catalog'
                                        : 'Override with a different catalog model'
                                }}
                            </Label>
                        </div>
                        <div class="flex items-center gap-2">
                            <Select
                                :model-value="assignKey"
                                @update:model-value="
                                    (value) =>
                                        (assignKey =
                                            typeof value === 'string'
                                                ? value
                                                : '')
                                "
                            >
                                <SelectTrigger class="h-8 flex-1 text-xs">
                                    <SelectValue placeholder="Pick a catalog model…" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="model in priced_models"
                                        :key="`${model.provider}|${model.model}`"
                                        :value="`${model.provider}|${model.model}`"
                                    >
                                        {{ model.provider }} / {{ model.model }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <Button
                                size="sm"
                                class="h-8 text-xs"
                                :disabled="!assignKey || assigning"
                                @click="assignPrice"
                            >
                                {{ assigning ? 'Assigning…' : 'Assign' }}
                            </Button>
                        </div>
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        size="sm"
                        variant="outline"
                        class="h-7 text-xs"
                        @click="closeDetail"
                    >
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
