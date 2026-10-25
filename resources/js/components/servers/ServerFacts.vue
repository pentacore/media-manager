<script setup lang="ts">
import { computed } from 'vue';
import { Pill, TimeStamp } from '@/components/mm';
import { formatBytes, formatCount } from '@/lib/format';
import { cn } from '@/lib/utils';
import { formatPercent, formatUptime } from './format';
import type { LatestReport, ServerDetail } from './types';

const props = defineProps<{
    server: ServerDetail;
    latest: LatestReport | null;
}>();

const load = computed(() =>
    props.latest ? props.latest.load.map((v) => v.toFixed(2)).join(' · ') : '—',
);

/** Whether the platform refused the agent's latest report: rejected after the last accepted one, or none got through. */
const latestReportRejected = computed(() => {
    const rejectedAt = props.server.last_report_error_at;

    if (!rejectedAt) {
        return false;
    }

    return (
        !props.server.last_report_at ||
        Date.parse(rejectedAt) > Date.parse(props.server.last_report_at)
    );
});

const runningContainers = computed(
    () =>
        props.latest?.containers?.filter(
            (container) => container.state === 'running',
        ).length ?? 0,
);
</script>

<template>
    <section
        class="rounded-xl border border-border bg-card p-5 text-[13px]"
        data-server-facts
    >
        <p
            v-if="server.last_report_error && server.last_report_error_at"
            :class="
                cn(
                    'mb-4 break-words',
                    latestReportRejected
                        ? 'text-warning'
                        : 'text-muted-foreground',
                )
            "
            :data-server-report-error="latestReportRejected ? 'latest' : 'past'"
        >
            {{
                latestReportRejected
                    ? "The platform is rejecting this agent's reports"
                    : 'Last rejected report'
            }}
            (<TimeStamp :iso="server.last_report_error_at" live />):
            <span class="font-mono-tabular">{{
                server.last_report_error
            }}</span>
        </p>
        <template v-if="server.last_report_at">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 lg:grid-cols-4">
                <div class="min-w-0">
                    <dt class="text-xs text-muted-foreground">Agent</dt>
                    <dd class="mt-0.5 flex flex-wrap items-center gap-1.5">
                        <span class="font-mono-tabular">{{
                            server.agent_version ?? '—'
                        }}</span>
                        <Pill
                            v-if="server.agent_outdated"
                            variant="warn"
                            data-server-agent-outdated
                            >Outdated</Pill
                        >
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs text-muted-foreground">Last report</dt>
                    <dd class="mt-0.5" data-server-last-report>
                        <TimeStamp :iso="server.last_report_at" live />
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs text-muted-foreground">Uptime</dt>
                    <dd class="mt-0.5 tabular-nums" data-server-uptime>
                        {{ formatUptime(server.uptime_seconds) }}
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs text-muted-foreground">Kernel</dt>
                    <dd class="font-mono-tabular mt-0.5 break-all">
                        {{ server.kernel ?? '—' }}
                    </dd>
                </div>
                <template v-if="latest">
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">CPU</dt>
                        <dd class="mt-0.5 tabular-nums" data-server-cpu>
                            {{ formatPercent(latest.cpu_percent) }}
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">
                            Load (1, 5, 15 min)
                        </dt>
                        <dd class="mt-0.5 tabular-nums">{{ load }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">Memory</dt>
                        <dd class="mt-0.5 tabular-nums" data-server-memory>
                            {{ formatPercent(latest.memory_used_percent) }}
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">
                            Containers
                        </dt>
                        <dd class="mt-0.5 tabular-nums">
                            <template v-if="latest.containers">
                                {{ formatCount(runningContainers) }} of
                                {{ formatCount(latest.containers.length) }}
                                running
                            </template>
                            <template v-else>—</template>
                        </dd>
                    </div>
                </template>
            </dl>

            <div v-if="latest && latest.filesystems.length" class="mt-5">
                <h3 class="mb-2 text-xs text-muted-foreground">Filesystems</h3>
                <ul class="divide-y divide-border">
                    <li
                        v-for="filesystem in latest.filesystems"
                        :key="filesystem.mount"
                        class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2"
                        :data-fs-row="filesystem.mount"
                    >
                        <span class="font-mono-tabular min-w-0 break-all">
                            {{ filesystem.mount }}
                            <span
                                v-if="filesystem.fstype"
                                class="ml-1 text-xs text-muted-foreground"
                                >{{ filesystem.fstype }}</span
                            >
                        </span>
                        <Pill
                            v-if="filesystem.unresponsive"
                            variant="danger"
                            data-fs-unresponsive
                            >Not responding</Pill
                        >
                        <span
                            v-else
                            class="text-muted-foreground tabular-nums"
                            data-fs-usage
                        >
                            <span class="text-foreground">{{
                                formatPercent(filesystem.used_percent)
                            }}</span>
                            · {{ formatBytes(filesystem.used_bytes) }} of
                            {{ formatBytes(filesystem.total_bytes) }}
                        </span>
                    </li>
                </ul>
            </div>

            <p
                v-if="latest?.failed_units?.length"
                class="mt-4 text-warning"
                data-server-failed-units
            >
                Failed units:
                <span class="font-mono-tabular break-all">{{
                    latest.failed_units.join(', ')
                }}</span>
            </p>
        </template>
        <p v-else-if="server.enrollment_expires_at">
            Waiting for the agent to enroll. The current enrollment token
            expires
            <TimeStamp :iso="server.enrollment_expires_at" mode="datetime" />.
            If you no longer have it, create a new one.
        </p>
        <p v-else class="text-muted-foreground">
            The agent has not reported yet.
        </p>
    </section>
</template>
