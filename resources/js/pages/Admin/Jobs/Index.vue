<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { RefreshCcw } from 'lucide-vue-next';
import { ref } from 'vue';
import JobsController from '@/actions/App/Http/Controllers/Admin/JobsController';
import { Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

interface QueuedJob {
    id: number;
    queue: string;
    class: string;
    attempts: number;
    reserved: boolean;
    available_at: string | null;
    created_at: string | null;
}

interface FailedJob {
    id: number;
    uuid: string;
    connection: string;
    queue: string;
    class: string;
    exception_class: string;
    message: string;
    failed_at: string;
}

interface ScheduledCommand {
    command: string;
    expression: string;
    description: string;
    without_overlapping: boolean;
    next_run: string | null;
}

defineProps<{
    queued: QueuedJob[];
    failed: FailedJob[];
    scheduled: ScheduledCommand[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'Jobs', href: JobsController.index.url() },
        ],
    },
});

const refreshing = ref(false);

function refresh(): void {
    refreshing.value = true;
    router.reload({
        onFinish: () => {
            refreshing.value = false;
        },
    });
}

function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString([], {
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

function shortClass(name: string): string {
    return name.split('\\').pop() ?? name;
}
</script>

<template>
    <Head title="Jobs" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero -->
        <div class="flex items-end justify-between gap-3">
            <div>
                <div class="mb-1.5 text-[13px] text-muted-foreground">
                    Admin <span class="text-fg-subtle">/</span> Jobs
                </div>
                <h1
                    class="text-[22px] leading-tight font-semibold tracking-tight"
                >
                    Jobs
                </h1>
                <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                    Read-only view of the queue, recent failures, and the
                    scheduled-command catalogue. Use this to verify that the
                    Laravel scheduler is alive and that nothing has piled up.
                </p>
            </div>
            <Button
                variant="outline"
                size="sm"
                class="h-7 gap-1.5 text-xs"
                :disabled="refreshing"
                @click="refresh"
            >
                <RefreshCcw
                    class="size-3.5"
                    :class="{ 'animate-spin': refreshing }"
                />Refresh
            </Button>
        </div>

        <!-- Queued -->
        <section
            class="overflow-hidden rounded-xl border border-border bg-card"
        >
            <div
                class="flex items-center justify-between border-b border-border px-4 py-3"
            >
                <span
                    class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                >
                    Queued ({{ queued.length }})
                </span>
            </div>
            <div
                v-if="queued.length === 0"
                class="px-4 py-6 text-center text-sm text-muted-foreground"
            >
                Queue is empty.
            </div>
            <table v-else class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            v-for="header in [
                                'ID',
                                'Queue',
                                'Class',
                                'Attempts',
                                'Reserved',
                                'Created',
                            ]"
                            :key="header"
                            class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            {{ header }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="job in queued"
                        :key="job.id"
                        class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                    >
                        <td class="font-mono-tabular px-3 py-2.5 text-[12px]">
                            {{ job.id }}
                        </td>
                        <td class="px-3 py-2.5">{{ job.queue }}</td>
                        <td class="px-3 py-2.5" :title="job.class">
                            {{ shortClass(job.class) }}
                        </td>
                        <td class="font-mono-tabular px-3 py-2.5 text-right">
                            {{ job.attempts }}
                        </td>
                        <td class="px-3 py-2.5">
                            <Pill v-if="job.reserved" variant="info"
                                >Reserved</Pill
                            >
                            <span v-else class="text-fg-subtle">—</span>
                        </td>
                        <td
                            class="font-mono-tabular px-3 py-2.5 text-[12px] text-muted-foreground"
                        >
                            {{ formatDate(job.created_at) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Failed -->
        <section
            class="overflow-hidden rounded-xl border border-border bg-card"
        >
            <div
                class="flex items-center justify-between border-b border-border px-4 py-3"
            >
                <span
                    class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                >
                    Recent failures ({{ failed.length }})
                </span>
            </div>
            <div
                v-if="failed.length === 0"
                class="px-4 py-6 text-center text-sm text-muted-foreground"
            >
                No failed jobs.
            </div>
            <table v-else class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            v-for="header in [
                                'Class',
                                'Queue',
                                'Exception',
                                'Failed at',
                            ]"
                            :key="header"
                            class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            {{ header }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="job in failed"
                        :key="job.uuid"
                        class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                    >
                        <td class="px-3 py-2.5" :title="job.class">
                            {{ shortClass(job.class) }}
                        </td>
                        <td class="px-3 py-2.5">{{ job.queue }}</td>
                        <td class="px-3 py-2.5">
                            <div
                                class="font-mono-tabular text-[11.5px] text-destructive"
                            >
                                {{ shortClass(job.exception_class) }}
                            </div>
                            <div
                                class="mt-0.5 text-[12px] text-muted-foreground"
                                :title="job.message"
                            >
                                {{ job.message }}
                            </div>
                        </td>
                        <td
                            class="font-mono-tabular px-3 py-2.5 text-[12px] text-muted-foreground"
                        >
                            {{ formatDate(job.failed_at) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Scheduled -->
        <section
            class="overflow-hidden rounded-xl border border-border bg-card"
        >
            <div
                class="flex items-center justify-between border-b border-border px-4 py-3"
            >
                <span
                    class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                >
                    Scheduled ({{ scheduled.length }})
                </span>
            </div>
            <div
                v-if="scheduled.length === 0"
                class="px-4 py-6 text-center text-sm text-muted-foreground"
            >
                No scheduled commands.
            </div>
            <table v-else class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            v-for="header in [
                                'Command',
                                'Schedule',
                                'Next run',
                            ]"
                            :key="header"
                            class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            {{ header }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(command, idx) in scheduled"
                        :key="idx"
                        class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                    >
                        <td class="font-mono-tabular px-3 py-2.5 text-[12px]">
                            {{ command.command }}
                            <Pill
                                v-if="command.without_overlapping"
                                variant="default"
                                class="ml-2 text-[10.5px]"
                            >
                                no-overlap
                            </Pill>
                        </td>
                        <td class="font-mono-tabular px-3 py-2.5 text-[12px]">
                            {{ command.expression }}
                        </td>
                        <td
                            class="font-mono-tabular px-3 py-2.5 text-[12px] text-muted-foreground"
                        >
                            {{ formatDate(command.next_run) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
