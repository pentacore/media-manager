<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AiModelPriceController from '@/actions/App/Http/Controllers/Admin/AiModelPriceController';
import AiUsageController from '@/actions/App/Http/Controllers/Admin/AiUsageController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

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
    agent_class: string | null;
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

const props = defineProps<{
    window: '24h' | '7d' | '30d';
    totals: Totals;
    by_agent: AggregateRow[];
    by_model: AggregateRow[];
    by_provider: AggregateRow[];
    recent: RecentRow[];
    priced_models: PricedModel[];
    scenario: ScenarioRates | null;
    scenario_totals?: Totals;
    scenario_by_agent?: AggregateRow[];
    scenario_by_model?: AggregateRow[];
    scenario_by_provider?: AggregateRow[];
    scenario_recent?: RecentRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: '#' },
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

const aggregatedScenarioByAgent = computed(() =>
    indexByKey(props.scenario_by_agent ?? []),
);
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

function buildQuery(extra: Record<string, string>): Record<string, unknown> {
    const query: Record<string, unknown> = { ...extra };

    if (props.scenario) {
        query.scenario = props.scenario;
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
        AiUsageController.index.url({
            query: { window: props.window },
        }),
        { preserveScroll: true },
    );
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

function shortClass(value: string | null): string {
    if (!value) {
        return '—';
    }

    const parts = value.split('\\');

    return parts[parts.length - 1] ?? value;
}

function formatTimestamp(value: string): string {
    return new Date(value).toLocaleString('en-US', {
        dateStyle: 'short',
        timeStyle: 'short',
    });
}
</script>

<template>
    <Head title="AI Usage" />

    <div class="space-y-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold">AI Usage</h1>
                <p class="text-sm text-muted-foreground">
                    Token consumption and estimated cost by agent, model, and
                    provider. Costs computed from
                    <a
                        :href="AiModelPriceController.index.url()"
                        class="underline hover:text-foreground"
                        >model prices</a
                    >.
                </p>
            </div>

            <div class="flex gap-1 rounded-md border bg-muted p-1">
                <Button
                    v-for="option in (['24h', '7d', '30d'] as const)"
                    :key="option"
                    :variant="props.window === option ? 'default' : 'ghost'"
                    size="sm"
                    @click="setWindow(option)"
                >
                    {{ option }}
                </Button>
            </div>
        </div>

        <Card>
            <CardHeader class="cursor-pointer pb-3" @click="panelOpen = !panelOpen">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <ChevronDown v-if="panelOpen" class="size-4" />
                        <ChevronRight v-else class="size-4" />
                        <CardTitle class="text-base">What-if Scenario</CardTitle>
                        <Badge v-if="scenarioActive" variant="secondary">Active</Badge>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Recompute costs against hypothetical rates
                    </p>
                </div>
            </CardHeader>
            <CardContent v-if="panelOpen" class="space-y-4">
                <div class="space-y-2">
                    <Label>Load rates from existing model</Label>
                    <Select
                        :model-value="selectedLoadKey"
                        @update:model-value="(value: string | string[]) => loadFromModel(typeof value === 'string' ? value : '')"
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Pick a priced model to copy its rates…" />
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
                    <p class="text-sm text-muted-foreground">
                        Or type custom rates below. All values are dollars per
                        million tokens.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                    <div class="space-y-1">
                        <Label for="rate_input">Input</Label>
                        <Input
                            id="rate_input"
                            type="number"
                            step="0.0001"
                            min="0"
                            v-model.number="form.input"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="rate_output">Output</Label>
                        <Input
                            id="rate_output"
                            type="number"
                            step="0.0001"
                            min="0"
                            v-model.number="form.output"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="rate_cache_read">Cache Read</Label>
                        <Input
                            id="rate_cache_read"
                            type="number"
                            step="0.0001"
                            min="0"
                            v-model.number="form.cache_read"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="rate_cache_write">Cache Write</Label>
                        <Input
                            id="rate_cache_write"
                            type="number"
                            step="0.0001"
                            min="0"
                            v-model.number="form.cache_write"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="rate_reasoning">Reasoning</Label>
                        <Input
                            id="rate_reasoning"
                            type="number"
                            step="0.0001"
                            min="0"
                            v-model.number="form.reasoning"
                        />
                    </div>
                </div>

                <div class="flex gap-2">
                    <Button @click="applyScenario">Apply</Button>
                    <Button
                        v-if="scenarioActive"
                        variant="outline"
                        @click="clearScenario"
                        >Clear</Button
                    >
                </div>
            </CardContent>
        </Card>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground"
                        >Estimated Cost</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-semibold">
                        {{ formatCost(totals.total_cost) }}
                    </div>
                    <div
                        v-if="scenarioActive && scenario_totals"
                        class="mt-1 text-sm"
                    >
                        <span class="font-medium text-primary"
                            >{{ formatCost(scenario_totals.total_cost) }}
                            projected</span
                        >
                        <span class="ml-1 text-muted-foreground"
                            >({{
                                costDelta(
                                    totals.total_cost,
                                    scenario_totals.total_cost,
                                )
                            }})</span
                        >
                    </div>
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground"
                        >Invocations</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-semibold">
                        {{ formatNumber(totals.total_invocations) }}
                    </div>
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground"
                        >Total Tokens</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-semibold">
                        {{ formatNumber(totals.total_tokens) }}
                    </div>
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground"
                        >Tool Calls</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-semibold">
                        {{ formatNumber(totals.total_tool_calls) }}
                    </div>
                </CardContent>
            </Card>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Card>
                <CardHeader>
                    <CardTitle>By Agent</CardTitle>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Agent</TableHead>
                                <TableHead class="text-right">Invocations</TableHead>
                                <TableHead class="text-right">Cost</TableHead>
                                <TableHead v-if="scenarioActive" class="text-right">
                                    Projected
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="row in by_agent"
                                :key="row.key ?? 'null'"
                            >
                                <TableCell>{{ shortClass(row.key) }}</TableCell>
                                <TableCell class="text-right">{{
                                    formatNumber(row.invocations)
                                }}</TableCell>
                                <TableCell class="text-right">{{
                                    formatCost(row.total_cost)
                                }}</TableCell>
                                <TableCell v-if="scenarioActive" class="text-right text-primary">
                                    {{
                                        formatCost(
                                            aggregatedScenarioByAgent[
                                                row.key ?? '__null__'
                                            ] ?? '0',
                                        )
                                    }}
                                </TableCell>
                            </TableRow>
                            <TableEmpty
                                v-if="by_agent.length === 0"
                                :colspan="scenarioActive ? 4 : 3"
                            >
                                No data in this window.
                            </TableEmpty>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>By Model</CardTitle>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Model</TableHead>
                                <TableHead class="text-right">Invocations</TableHead>
                                <TableHead class="text-right">Cost</TableHead>
                                <TableHead v-if="scenarioActive" class="text-right">
                                    Projected
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="row in by_model"
                                :key="row.key ?? 'null'"
                            >
                                <TableCell>{{ row.key ?? '—' }}</TableCell>
                                <TableCell class="text-right">{{
                                    formatNumber(row.invocations)
                                }}</TableCell>
                                <TableCell class="text-right">{{
                                    formatCost(row.total_cost)
                                }}</TableCell>
                                <TableCell v-if="scenarioActive" class="text-right text-primary">
                                    {{
                                        formatCost(
                                            aggregatedScenarioByModel[
                                                row.key ?? '__null__'
                                            ] ?? '0',
                                        )
                                    }}
                                </TableCell>
                            </TableRow>
                            <TableEmpty
                                v-if="by_model.length === 0"
                                :colspan="scenarioActive ? 4 : 3"
                            >
                                No data in this window.
                            </TableEmpty>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>By Provider</CardTitle>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Provider</TableHead>
                                <TableHead class="text-right">Invocations</TableHead>
                                <TableHead class="text-right">Cost</TableHead>
                                <TableHead v-if="scenarioActive" class="text-right">
                                    Projected
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="row in by_provider"
                                :key="row.key ?? 'null'"
                            >
                                <TableCell>{{ row.key ?? '—' }}</TableCell>
                                <TableCell class="text-right">{{
                                    formatNumber(row.invocations)
                                }}</TableCell>
                                <TableCell class="text-right">{{
                                    formatCost(row.total_cost)
                                }}</TableCell>
                                <TableCell v-if="scenarioActive" class="text-right text-primary">
                                    {{
                                        formatCost(
                                            aggregatedScenarioByProvider[
                                                row.key ?? '__null__'
                                            ] ?? '0',
                                        )
                                    }}
                                </TableCell>
                            </TableRow>
                            <TableEmpty
                                v-if="by_provider.length === 0"
                                :colspan="scenarioActive ? 4 : 3"
                            >
                                No data in this window.
                            </TableEmpty>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Recent Invocations</CardTitle>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>When</TableHead>
                            <TableHead>User</TableHead>
                            <TableHead>Agent</TableHead>
                            <TableHead>Model</TableHead>
                            <TableHead class="text-right">Tokens</TableHead>
                            <TableHead class="text-right">Tools</TableHead>
                            <TableHead class="text-right">Cost</TableHead>
                            <TableHead v-if="scenarioActive" class="text-right">
                                Projected
                            </TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="row in recent" :key="row.id">
                            <TableCell class="whitespace-nowrap text-sm text-muted-foreground">
                                {{ formatTimestamp(row.created_at) }}
                            </TableCell>
                            <TableCell>{{ row.user_name ?? '—' }}</TableCell>
                            <TableCell>{{ shortClass(row.agent_class) }}</TableCell>
                            <TableCell class="font-mono text-xs">{{ row.model ?? '—' }}</TableCell>
                            <TableCell class="text-right">{{ formatNumber(row.total_tokens) }}</TableCell>
                            <TableCell class="text-right">{{ row.tool_calls_count }}</TableCell>
                            <TableCell class="text-right">{{ formatCost(row.cost) }}</TableCell>
                            <TableCell v-if="scenarioActive" class="text-right text-primary">
                                {{ formatCost(indexedScenarioRecent[row.id] ?? '0') }}
                            </TableCell>
                            <TableCell>
                                <Badge :variant="row.status === 'success' ? 'secondary' : 'destructive'">
                                    {{ row.status }}
                                </Badge>
                            </TableCell>
                        </TableRow>
                        <TableEmpty
                            v-if="recent.length === 0"
                            :colspan="scenarioActive ? 9 : 8"
                        >
                            No invocations in this window.
                        </TableEmpty>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    </div>
</template>
