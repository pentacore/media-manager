<script setup lang="ts">
import { Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AssignCatalogPrice from './AssignCatalogPrice.vue';
import { formatTimestamp } from './format';
import InvocationChildRuns from './InvocationChildRuns.vue';
import InvocationCostBreakdown from './InvocationCostBreakdown.vue';
import InvocationToolList from './InvocationToolList.vue';
import type { InvocationDetail, PricedModel } from './types';

/**
 * The invocation drill-down. Open while loading, on a load error, or with a
 * detail; the page owns that state (useInvocationDetail) and closes it on
 * `close`.
 */
defineProps<{
    detail: InvocationDetail | null;
    loading: boolean;
    error: string | null;
    assigning: boolean;
    pricedModels: PricedModel[];
}>();

const assignKey = defineModel<string>('assignKey', { required: true });

const emit = defineEmits<{ close: []; assign: [] }>();

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
</script>

<template>
    <Dialog
        :open="detail !== null || loading || error !== null"
        @update:open="(v) => !v && emit('close')"
    >
        <DialogContent
            class="flex max-h-[85vh] max-w-3xl flex-col"
            data-usage-detail
        >
            <DialogHeader>
                <DialogTitle>Invocation detail</DialogTitle>
            </DialogHeader>

            <div
                v-if="loading"
                class="px-2 py-8 text-center text-sm text-muted-foreground"
            >
                Loading…
            </div>
            <div
                v-else-if="error"
                class="px-2 py-8 text-center text-sm text-destructive"
            >
                {{ error }}
            </div>
            <div
                v-else-if="detail"
                class="-mr-2 flex-1 space-y-5 overflow-y-auto pr-2"
            >
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
                                    ? formatTimestamp(detail.record.created_at)
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
                        <div class="font-mono-tabular text-[12px] break-all">
                            {{
                                detail.record.agent_class?.split('\\').pop() ??
                                '—'
                            }}
                        </div>
                    </div>
                    <div>
                        <div
                            class="text-[11px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            Invocation
                        </div>
                        <div class="font-mono-tabular text-[12px] break-all">
                            {{ detail.record.invocation_id }}
                        </div>
                    </div>
                </div>

                <!-- Prompt sent and final response -->
                <template
                    v-for="io in [
                        {
                            key: 'input',
                            label: 'Input',
                            text: detail.record.prompt_text,
                        },
                        {
                            key: 'output',
                            label: 'Output',
                            text: detail.record.response_text,
                        },
                    ]"
                    :key="io.key"
                >
                    <div
                        v-if="io.text"
                        class="rounded-md border border-border bg-bg-elev"
                        :data-usage-io="io.key"
                    >
                        <div
                            class="flex items-center justify-between border-b border-border px-3 py-1.5"
                        >
                            <span
                                class="text-[11px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                {{ io.label }}
                            </span>
                        </div>
                        <pre
                            class="max-h-72 overflow-y-auto px-3 py-2 text-[12px] leading-snug break-words whitespace-pre-wrap"
                            >{{ io.text }}</pre>
                    </div>
                </template>

                <!-- Pricing source banner -->
                <div
                    class="flex items-center justify-between rounded-md border border-border bg-bg-elev px-3 py-2"
                    data-usage-pricing
                >
                    <div class="flex items-center gap-2 text-[12px]">
                        <span class="text-muted-foreground">Pricing:</span>
                        <Pill :variant="rateSourceVariant(detail.rates.source)">
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
                        No price available — assign one below to recompute cost.
                    </div>
                </div>

                <!-- Cost breakdown -->
                <InvocationCostBreakdown :detail="detail" />

                <!-- Tools -->
                <InvocationToolList
                    v-if="detail.tools.length > 0"
                    :tools="detail.tools"
                />

                <!-- Sub-agent runs -->
                <InvocationChildRuns
                    v-if="detail.children.length > 0"
                    :runs="detail.children"
                />

                <!-- Failure message -->
                <div
                    v-if="detail.record.error_message"
                    class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-[12px] text-destructive"
                >
                    {{ detail.record.error_message }}
                </div>

                <!-- Retroactive price assignment -->
                <AssignCatalogPrice
                    v-if="
                        detail.rates.source === 'unpriced' ||
                        detail.record.price_source !== 'assigned'
                    "
                    v-model="assignKey"
                    :unpriced="detail.rates.source === 'unpriced'"
                    :priced-models="pricedModels"
                    :assigning="assigning"
                    @assign="emit('assign')"
                />
            </div>

            <DialogFooter>
                <Button
                    size="sm"
                    variant="outline"
                    class="h-7 text-xs"
                    data-usage-detail-close
                    @click="emit('close')"
                >
                    Close
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
