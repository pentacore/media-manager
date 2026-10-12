<script setup lang="ts">
import { computed } from 'vue';
import { Pill } from '@/components/mm';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import {
    candidateLabel,
    formatLatency,
    formatScore,
    formatUsd,
} from './format';
import type {
    BenchmarkResultDetail,
    BenchmarkSelection,
    BenchmarkToolLogEntry,
} from './types';

const props = defineProps<{
    detail: BenchmarkResultDetail | null;
    candidates: BenchmarkSelection[];
    loading: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });

const results = computed(() => props.detail?.results ?? []);

function jsonLine(value: unknown): string {
    return JSON.stringify(value);
}

function diffRows(result: BenchmarkResultDetail['results'][number]): Array<{
    label: string;
    items: unknown[];
}> {
    const detail = result.score_detail;

    return [
        { label: 'Matched', items: detail.matched ?? [] },
        { label: 'Missing', items: detail.missing ?? [] },
        { label: 'Extra', items: detail.extra ?? [] },
        { label: 'Mismatches', items: detail.mismatches ?? [] },
    ].filter((row) => row.items.length > 0);
}

function toolBadge(entry: BenchmarkToolLogEntry): string {
    return entry.write ? 'write' : entry.hit ? 'hit' : 'miss';
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            class="w-full overflow-y-auto sm:max-w-5xl"
            data-benchmark-drawer
        >
            <SheetHeader>
                <SheetTitle>{{ detail?.case_name ?? 'Result' }}</SheetTitle>
                <SheetDescription>
                    Every candidate's answer to this case.
                </SheetDescription>
            </SheetHeader>

            <div class="flex flex-col gap-4 px-4 pb-6">
                <div v-if="loading || detail === null" class="space-y-3">
                    <Skeleton class="h-4 w-3/4" />
                    <Skeleton class="h-24 w-full" />
                </div>
                <template v-else>
                    <section v-if="detail.prompt" data-benchmark-drawer-prompt>
                        <h3
                            class="mb-1 text-[12px] font-semibold text-muted-foreground"
                        >
                            Case prompt
                        </h3>
                        <p
                            class="max-h-40 overflow-y-auto rounded-md border border-border bg-bg-elev p-3 text-[13px] whitespace-pre-wrap"
                        >
                            {{ detail.prompt }}
                        </p>
                    </section>

                    <div
                        class="grid min-w-0 gap-4 md:auto-cols-fr md:grid-flow-col"
                    >
                        <article
                            v-for="result in results"
                            :key="result.result_id"
                            class="flex min-w-0 flex-col gap-2 rounded-lg border border-border p-3"
                            :data-benchmark-drawer-candidate="
                                result.candidate_index
                            "
                        >
                            <header class="flex flex-wrap items-center gap-2">
                                <span class="text-[13px] font-semibold">
                                    {{
                                        candidates[result.candidate_index]
                                            ? candidateLabel(
                                                  candidates[
                                                      result.candidate_index
                                                  ],
                                              )
                                            : `Candidate ${result.candidate_index + 1}`
                                    }}
                                </span>
                                <Pill
                                    :variant="
                                        result.status === 'ok'
                                            ? 'ok'
                                            : result.status === 'failed'
                                              ? 'danger'
                                              : 'default'
                                    "
                                    >{{ result.status }}</Pill
                                >
                                <Pill
                                    v-if="result.score !== null"
                                    :variant="result.passed ? 'ok' : 'danger'"
                                    data-benchmark-drawer-score
                                    >{{ formatScore(result.score) }} / 10</Pill
                                >
                                <Pill
                                    v-else-if="result.status === 'ok'"
                                    variant="warn"
                                    data-benchmark-drawer-unscored
                                    >Unscored — judge failed or
                                    re-judging…</Pill
                                >
                            </header>

                            <p class="text-[12px] text-muted-foreground">
                                {{ formatLatency(result.latency_ms) }} ·
                                {{ formatUsd(result.cost_usd) }}
                            </p>

                            <p
                                v-if="result.error"
                                class="rounded-md border border-destructive/30 bg-destructive/10 p-2 text-[12.5px] text-destructive"
                                data-benchmark-drawer-error
                            >
                                {{ result.error }}
                            </p>

                            <div v-if="result.output !== null">
                                <h4
                                    class="mb-1 text-[12px] font-semibold text-muted-foreground"
                                >
                                    Output
                                </h4>
                                <pre
                                    class="max-h-72 overflow-auto rounded-md border border-border bg-bg-elev p-2 font-mono text-[12px] break-words whitespace-pre-wrap"
                                    data-benchmark-drawer-output
                                    >{{ result.output }}</pre>
                            </div>

                            <p
                                v-if="result.score_detail.rationale"
                                class="text-[12.5px]"
                                data-benchmark-drawer-rationale
                            >
                                <span class="font-semibold">Judge:</span>
                                {{ result.score_detail.rationale }}
                            </p>
                            <p
                                v-if="result.score_detail.error"
                                class="text-[12.5px] text-warning"
                            >
                                Judge failed: {{ result.score_detail.error }}
                            </p>

                            <div
                                v-for="row in diffRows(result)"
                                :key="row.label"
                                class="text-[12px]"
                                data-benchmark-drawer-diff
                            >
                                <span class="font-semibold"
                                    >{{ row.label }}:</span
                                >
                                <code
                                    v-for="(item, index) in row.items"
                                    :key="index"
                                    class="mt-0.5 block font-mono break-words"
                                    >{{ jsonLine(item) }}</code
                                >
                            </div>

                            <div v-if="result.intended_actions.length > 0">
                                <h4
                                    class="mb-1 text-[12px] font-semibold text-muted-foreground"
                                >
                                    Intended actions
                                </h4>
                                <code
                                    v-for="(
                                        action, index
                                    ) in result.intended_actions"
                                    :key="index"
                                    class="block font-mono text-[12px] break-words"
                                    data-benchmark-drawer-action
                                    >{{ action.tool }}
                                    {{ jsonLine(action.arguments) }}</code
                                >
                            </div>

                            <div v-if="result.tool_log.length > 0">
                                <h4
                                    class="mb-1 text-[12px] font-semibold text-muted-foreground"
                                >
                                    Tool log
                                </h4>
                                <ul class="flex flex-col gap-1">
                                    <li
                                        v-for="(
                                            entry, index
                                        ) in result.tool_log"
                                        :key="index"
                                        class="flex flex-wrap items-start gap-1.5 text-[12px]"
                                        data-benchmark-drawer-tool
                                    >
                                        <Pill
                                            :variant="
                                                entry.write
                                                    ? 'info'
                                                    : entry.hit
                                                      ? 'ok'
                                                      : 'warn'
                                            "
                                            >{{ toolBadge(entry) }}</Pill
                                        >
                                        <code class="font-mono break-words"
                                            >{{ entry.tool }}
                                            {{
                                                jsonLine(entry.arguments)
                                            }}</code
                                        >
                                    </li>
                                </ul>
                            </div>
                        </article>
                    </div>
                </template>
            </div>
        </SheetContent>
    </Sheet>
</template>
