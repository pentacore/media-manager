<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { KeyRound, Trash2 } from '@lucide/vue';
import ServerController from '@/actions/App/Http/Controllers/Admin/ServerController';
import { PageHeader, TimeStamp } from '@/components/mm';
import { EnrollmentDialog, ServerStatusDot } from '@/components/servers';
import type { ServerStatus } from '@/components/servers';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { useConfirm } from '@/composables/useConfirm';
import { dashboard } from '@/routes';

const props = defineProps<{
    server: {
        id: number;
        name: string;
        hostname: string | null;
        os: string | null;
        arch: string | null;
        agent_version: string | null;
        status: ServerStatus;
        status_label: string;
        enrolled_at: string | null;
        last_report_at: string | null;
        enrollment_expires_at: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'Servers', href: ServerController.index.url() },
        ],
    },
});

function regenerate(): void {
    router.post(
        ServerController.regenerateEnrollment.url(props.server.id),
        {},
        { preserveScroll: true },
    );
}

const { confirm } = useConfirm();

async function remove(): Promise<void> {
    const confirmed = await confirm({
        title: `Remove "${props.server.name}"?`,
        description:
            'Its checks, incidents and metrics are deleted and its agent can no longer report. This cannot be undone.',
        confirmLabel: 'Remove',
        destructive: true,
    });

    if (!confirmed) {
        return;
    }

    router.delete(ServerController.destroy.url(props.server.id));
}
</script>

<template>
    <Head :title="server.name" />

    <div class="space-y-6 p-6">
        <PageHeader align="start" class="sm:gap-4">
            <template #title>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-[20px] font-semibold break-words">
                        {{ server.name }}
                    </h1>
                    <ServerStatusDot
                        :status="server.status"
                        :label="server.status_label"
                    />
                </div>
            </template>
            <template #description>
                <p
                    class="mt-1 text-[12.5px] break-words text-muted-foreground"
                    data-server-description
                >
                    <template v-if="server.hostname">
                        {{
                            [server.hostname, server.os, server.arch]
                                .filter(Boolean)
                                .join(' · ')
                        }}
                    </template>
                    <template v-else>No agent has enrolled yet.</template>
                </p>
            </template>
            <template #secondaryActions>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    class="h-8 gap-1.5 text-xs"
                    data-server-regenerate
                    @click="regenerate"
                >
                    <KeyRound class="size-3.5" />New enrollment token
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="destructive"
                    class="h-8 gap-1.5 text-xs"
                    data-server-delete
                    @click="remove"
                >
                    <Trash2 class="size-3.5" />Remove server
                </Button>
            </template>
            <template #menu>
                <DropdownMenuItem
                    data-page-header-item="regenerate"
                    @select="regenerate"
                >
                    <KeyRound class="size-3.5" />New enrollment token
                </DropdownMenuItem>
                <DropdownMenuItem
                    variant="destructive"
                    data-page-header-item="delete"
                    @select="remove"
                >
                    <Trash2 class="size-3.5" />Remove server
                </DropdownMenuItem>
            </template>
        </PageHeader>

        <div
            class="rounded-xl border border-border bg-card p-5 text-[13px]"
            data-server-summary
        >
            <p v-if="server.last_report_at">
                Last report
                <TimeStamp :iso="server.last_report_at" live />
                <template v-if="server.agent_version">
                    from agent
                    <span class="font-mono-tabular">{{
                        server.agent_version
                    }}</span></template
                >.
            </p>
            <p v-else-if="server.enrollment_expires_at">
                Waiting for the agent to enroll. The current enrollment token
                expires
                <TimeStamp
                    :iso="server.enrollment_expires_at"
                    mode="datetime"
                />. If you no longer have it, create a new one.
            </p>
            <p v-else class="text-muted-foreground">
                The agent has not reported yet.
            </p>
        </div>

        <EnrollmentDialog :server-id="server.id" :server-name="server.name" />
    </div>
</template>
