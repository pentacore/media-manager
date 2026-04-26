<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AiModelPriceController from '@/actions/App/Http/Controllers/Admin/AiModelPriceController';
import AiUsageController from '@/actions/App/Http/Controllers/Admin/AiUsageController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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

const props = defineProps<{
    window: '24h' | '7d' | '30d';
    totals: Totals;
    by_agent: AggregateRow[];
    by_model: AggregateRow[];
    by_provider: AggregateRow[];
    recent: RecentRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: '#' },
            { title: 'AI Usage', href: AiUsageController.index.url() },
        ],
    },
});

function setWindow(value: string) {
    router.visit(AiUsageController.index.url({ query: { window: value } }), {
        preserveScroll: true,
        preserveState: true,
    });
}

function formatCost(value: string | number): string {
    const n = typeof value === 'string' ? parseFloat(value) : value;

    if (n < 0.01 && n > 0) {
        return `$${n.toFixed(5)}`;
    }

    return `$${n.toFixed(2)}`;
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
                                <TableHead class="text-right"
                                    >Invocations</TableHead
                                >
                                <TableHead class="text-right">Cost</TableHead>
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
                            </TableRow>
                            <TableEmpty v-if="by_agent.length === 0" :colspan="3">
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
                                <TableHead class="text-right"
                                    >Invocations</TableHead
                                >
                                <TableHead class="text-right">Cost</TableHead>
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
                            </TableRow>
                            <TableEmpty v-if="by_model.length === 0" :colspan="3">
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
                                <TableHead class="text-right"
                                    >Invocations</TableHead
                                >
                                <TableHead class="text-right">Cost</TableHead>
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
                            </TableRow>
                            <TableEmpty v-if="by_provider.length === 0" :colspan="3">
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
                            <TableCell>
                                <Badge :variant="row.status === 'success' ? 'secondary' : 'destructive'">
                                    {{ row.status }}
                                </Badge>
                            </TableCell>
                        </TableRow>
                        <TableEmpty v-if="recent.length === 0" :colspan="8">
                            No invocations in this window.
                        </TableEmpty>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    </div>
</template>
