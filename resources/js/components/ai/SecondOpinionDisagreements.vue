<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ActionRequestController from '@/actions/App/Http/Controllers/Actions/ActionRequestController';
import BenchmarkCaseController from '@/actions/App/Http/Controllers/Admin/BenchmarkCaseController';
import type { AgreementDisagreement } from '@/components/ai/secondOpinionAgreement';
import { Pill, TimeStamp } from '@/components/mm';
import { Button } from '@/components/ui/button';

defineProps<{ rows: AgreementDisagreement[] }>();
</script>

<template>
    <div class="flex min-w-0 flex-col gap-2" data-agreement-disagreements>
        <span class="text-[13px] font-medium"
            >Recent disagreements with reviewers</span
        >
        <p v-if="rows.length === 0" class="text-[13px] text-muted-foreground">
            No reviewer disagreed with the second opinion in this window.
        </p>
        <ul
            v-else
            class="flex flex-col divide-y divide-border rounded-lg border border-border"
        >
            <li
                v-for="row in rows"
                :key="row.id"
                class="flex min-w-0 flex-col gap-1.5 px-3 py-2.5"
                :data-disagreement="row.id"
            >
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <Pill
                        :variant="row.kind === 'miss' ? 'danger' : 'warn'"
                        data-disagreement-kind
                        >{{
                            row.kind === 'miss' ? 'Miss' : 'False alarm'
                        }}</Pill
                    >
                    <span
                        class="min-w-0 text-[13px] font-medium break-words"
                        data-disagreement-title
                        >{{ row.title ?? `Action request #${row.id}` }}</span
                    >
                </div>
                <div
                    class="flex flex-wrap gap-x-2 text-[11.5px] text-muted-foreground"
                >
                    <span class="break-all" data-disagreement-event>{{
                        row.event_key ?? 'Unknown event'
                    }}</span>
                    <span>·</span>
                    <TimeStamp :iso="row.created_at" />
                </div>
                <p
                    v-if="row.reason"
                    class="text-[12px] break-words text-muted-foreground"
                    data-disagreement-reason
                >
                    {{ row.reason }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" as-child>
                        <Link
                            :href="
                                ActionRequestController.index.url({
                                    query: {
                                        status: row.status,
                                        request: row.id,
                                    },
                                })
                            "
                            data-disagreement-request
                        >
                            Open request
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" as-child>
                        <Link
                            :href="
                                BenchmarkCaseController.create.url({
                                    query: { action_request: row.id },
                                })
                            "
                            data-disagreement-benchmark
                        >
                            Benchmark this
                        </Link>
                    </Button>
                </div>
            </li>
        </ul>
    </div>
</template>
