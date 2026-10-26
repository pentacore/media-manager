<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import DecisionAgentSettingsController from '@/actions/App/Http/Controllers/Admin/DecisionAgentSettingsController';
import { dailyBars, percent } from '@/components/ai/secondOpinionAgreement';
import type { SecondOpinionAgreement } from '@/components/ai/secondOpinionAgreement';
import { BarChart, StatCard } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { formatCount } from '@/lib/format';
import SecondOpinionBreakdownTable from './SecondOpinionBreakdownTable.vue';
import SecondOpinionDisagreements from './SecondOpinionDisagreements.vue';

const props = defineProps<{ agreement: SecondOpinionAgreement }>();

const totals = computed(() => props.agreement.totals);
const rates = computed(() => props.agreement.rates);
const reviewedHolds = computed(
    () => totals.value.disagreed_rejected + totals.value.disagreed_approved,
);

const countsLine = computed(() => {
    const parts = [
        `${formatCount(totals.value.verdicts)} verdicts`,
        `${formatCount(totals.value.failed)} failed`,
        `${formatCount(totals.value.rejected_by_verifier)} rejected by the verifier`,
        `${formatCount(totals.value.awaiting_review)} awaiting review`,
        `${formatCount(totals.value.skipped)} skipped by a per-event rule`,
    ];

    if (totals.value.unattributed > 0) {
        parts.push(
            `${formatCount(totals.value.unattributed)} without a reviewer on record`,
        );
    }

    return parts.join(' · ');
});

const headerClass =
    'border-b border-border px-3 py-2 text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase';
</script>

<template>
    <div
        class="overflow-hidden rounded-xl border border-border bg-card"
        data-second-opinion-agreement
    >
        <div
            class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3"
        >
            <div class="flex min-w-0 flex-col gap-0.5">
                <span
                    class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                >
                    Second opinion
                </span>
                <span class="text-[11.5px] text-muted-foreground">
                    How often people who reviewed an action agreed with the
                    second opinion. Reject-mode rejections and auto-run actions
                    are never reviewed, so they don't count.
                </span>
            </div>
            <Button variant="ghost" size="sm" as-child>
                <Link
                    :href="DecisionAgentSettingsController.index.url()"
                    data-agreement-tune
                >
                    Tune per event →
                </Link>
            </Button>
        </div>
        <p
            v-if="totals.verdicts === 0"
            class="px-4 py-6 text-center text-sm text-fg-subtle"
            data-agreement-empty
        >
            No second opinions in this window.
        </p>
        <div v-else class="flex flex-col gap-4 p-4">
            <div
                class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5"
                data-agreement-stats
            >
                <StatCard
                    label="Agreement"
                    :value="percent(rates.agreement)"
                    :hint="`of ${formatCount(totals.reviewed)} reviewed`"
                    data-agreement-stat="agreement"
                />
                <StatCard
                    label="Holds that were right"
                    :value="percent(rates.holds_right)"
                    :hint="`of ${formatCount(reviewedHolds)} reviewed holds`"
                    data-agreement-stat="holds_right"
                />
                <StatCard
                    label="False alarms"
                    :value="formatCount(totals.disagreed_approved)"
                    hint="disagreed, person approved"
                    :class="
                        totals.disagreed_approved > 0 ? 'text-destructive' : ''
                    "
                    data-agreement-stat="false_alarms"
                />
                <StatCard
                    label="Misses"
                    :value="formatCount(totals.agreed_rejected)"
                    hint="agreed, person rejected"
                    :class="
                        totals.agreed_rejected > 0 ? 'text-destructive' : ''
                    "
                    data-agreement-stat="misses"
                />
                <StatCard
                    label="Coverage"
                    :value="percent(rates.coverage)"
                    :hint="`of ${formatCount(totals.verdicts)} verdicts`"
                    data-agreement-stat="coverage"
                />
            </div>
            <p
                class="text-[11.5px] text-muted-foreground"
                data-agreement-counts
            >
                {{ countsLine }}
            </p>
            <div
                class="grid grid-cols-1 gap-4 lg:grid-cols-2"
                data-agreement-overview
            >
                <div
                    class="min-w-0 overflow-x-auto rounded-lg border border-border"
                >
                    <table
                        class="w-full border-collapse text-[13px]"
                        data-agreement-matrix
                    >
                        <thead>
                            <tr>
                                <th :class="[headerClass, 'text-left']">
                                    Reviewed verdicts
                                </th>
                                <th :class="[headerClass, 'text-right']">
                                    Person approved
                                </th>
                                <th :class="[headerClass, 'text-right']">
                                    Person rejected
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b border-border">
                                <th
                                    scope="row"
                                    class="px-3 py-2 text-left font-medium"
                                >
                                    Verifier agreed
                                </th>
                                <td
                                    class="px-3 py-2 text-right tabular-nums"
                                    data-agreement-cell="agreed_approved"
                                >
                                    {{ formatCount(totals.agreed_approved) }}
                                    <span class="text-muted-foreground"
                                        >right</span
                                    >
                                </td>
                                <td
                                    class="px-3 py-2 text-right tabular-nums"
                                    data-agreement-cell="agreed_rejected"
                                >
                                    {{ formatCount(totals.agreed_rejected) }}
                                    <span
                                        :class="
                                            totals.agreed_rejected > 0
                                                ? 'text-destructive'
                                                : 'text-muted-foreground'
                                        "
                                        >missed</span
                                    >
                                </td>
                            </tr>
                            <tr>
                                <th
                                    scope="row"
                                    class="px-3 py-2 text-left font-medium"
                                >
                                    Verifier disagreed
                                </th>
                                <td
                                    class="px-3 py-2 text-right tabular-nums"
                                    data-agreement-cell="disagreed_approved"
                                >
                                    {{ formatCount(totals.disagreed_approved) }}
                                    <span
                                        :class="
                                            totals.disagreed_approved > 0
                                                ? 'text-warning'
                                                : 'text-muted-foreground'
                                        "
                                        >false alarms</span
                                    >
                                </td>
                                <td
                                    class="px-3 py-2 text-right tabular-nums"
                                    data-agreement-cell="disagreed_rejected"
                                >
                                    {{ formatCount(totals.disagreed_rejected) }}
                                    <span class="text-muted-foreground"
                                        >right</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex min-w-0 flex-col gap-2" data-agreement-daily>
                    <span class="text-[13px] font-medium">{{
                        agreement.daily_bucket === 'week'
                            ? 'Agreement per week'
                            : 'Agreement per day'
                    }}</span>
                    <BarChart
                        :data="
                            dailyBars(agreement.daily, agreement.daily_bucket)
                        "
                        :height="90"
                        :max="100"
                    />
                </div>
            </div>
            <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                <SecondOpinionBreakdownTable
                    title="By event"
                    heading="Event"
                    dimension="event"
                    :rows="agreement.by_event"
                    :overridden-events="agreement.overridden_events"
                />
                <SecondOpinionBreakdownTable
                    title="By action type"
                    heading="Action"
                    dimension="action_type"
                    :rows="agreement.by_action_type"
                />
                <SecondOpinionBreakdownTable
                    title="Answered by"
                    heading="Verifier"
                    dimension="answered_by"
                    :rows="agreement.by_answered_by"
                />
            </div>
            <SecondOpinionDisagreements :rows="agreement.disagreements" />
        </div>
    </div>
</template>
