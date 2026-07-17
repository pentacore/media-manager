<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { AlertTriangle, LoaderCircle, Search, Sparkles } from '@lucide/vue';
import { ref } from 'vue';
import AdvisorController from '@/actions/App/Http/Controllers/Bazarr/AdvisorController';
import EscalationController from '@/actions/App/Http/Controllers/Bazarr/EscalationController';
import OverviewController from '@/actions/App/Http/Controllers/Bazarr/OverviewController';
import SubtitleTabs from '@/components/bazarr/SubtitleTabs.vue';
import { StatusPill, TimeStamp } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

interface EscalationCase {
    id: number;
    display_name: string;
    media_type: string;
    scope: string;
    status: string;
    missing_languages: string[];
    probe_count: number;
    first_seen_at: string;
    last_probe_at: string | null;
    bazarr_connection: string;
    source_connection: string;
    download_action_status: string | null;
    replacement_action_status: string | null;
    advisor_summary: string | null;
}

interface PaginatorLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    connections: { id: number; name: string }[];
    selected_connection_id: number | null;
    requires_connection_selection: boolean;
    cases: {
        data: EscalationCase[];
        links: PaginatorLink[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    };
    filters: {
        connection: number | null;
        status: string;
    };
    filter_options: {
        connections: { id: number; name: string }[];
        statuses: { value: string; label: string }[];
    };
    can_filter: boolean;
    can_investigate: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Media', href: dashboard().url },
            { title: 'Subtitles', href: OverviewController.url() },
            { title: 'Escalations', href: EscalationController.url() },
        ],
    },
});

const investigatingCaseId = ref<number | null>(null);

usePoll(2_000, { only: ['cases'] }, { mode: 'rest' });

function updateFilters(connection: number | null, status: string): void {
    router.get(
        EscalationController.url(),
        {
            ...(connection ? { connection } : {}),
            ...(status ? { status } : {}),
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

function updateConnection(event: Event): void {
    const value = Number((event.target as HTMLSelectElement).value);
    updateFilters(value || null, props.filters.status);
}

function updateStatus(event: Event): void {
    updateFilters(
        props.filters.connection,
        (event.target as HTMLSelectElement).value,
    );
}

function actionSummary(escalationCase: EscalationCase): string {
    if (escalationCase.replacement_action_status) {
        return `Linked Action Request: replacement ${escalationCase.replacement_action_status}`;
    }

    if (escalationCase.download_action_status) {
        return `Linked Action Request: subtitle download ${escalationCase.download_action_status}`;
    }

    return 'Linked Action Request: none';
}

function advisorSummary(escalationCase: EscalationCase): string {
    if (escalationCase.advisor_summary) {
        return escalationCase.advisor_summary;
    }

    switch (escalationCase.status) {
        case 'replacement_eligible':
            return 'Ready for one Media Advisor investigation.';
        case 'advisor_running':
            return 'Media Advisor is inspecting this exact subtitle case.';
        case 'replacement_requested':
            return 'Media Advisor queued a replacement for approval or execution.';
        case 'needs_review':
            return 'The last investigation needs review before a manual retry.';
        case 'resolved':
            return 'The required subtitles are now present.';
        default:
            return 'No further Media Advisor action is available.';
    }
}

function canRequestAdvisor(escalationCase: EscalationCase): boolean {
    return ['replacement_eligible', 'needs_review'].includes(
        escalationCase.status,
    );
}

function advisorButtonLabel(escalationCase: EscalationCase): string {
    if (investigatingCaseId.value === escalationCase.id) {
        return 'Queuing investigation…';
    }

    if (escalationCase.status === 'needs_review') {
        return 'Retry with Media Advisor';
    }

    if (escalationCase.status === 'advisor_running') {
        return 'Media Advisor investigating…';
    }

    return 'Investigate with Media Advisor';
}

function investigate(escalationCase: EscalationCase): void {
    if (
        escalationCase.status === 'needs_review' &&
        !confirm(
            'This case has already been investigated. Retry it manually with Media Advisor?',
        )
    ) {
        return;
    }

    if (!props.selected_connection_id) {
        return;
    }

    router.post(
        AdvisorController.url(escalationCase.id, {
            query: { connection: props.selected_connection_id },
        }),
        {
            confirm_retry: escalationCase.status === 'needs_review',
        },
        {
            preserveScroll: true,
            onStart: () => {
                investigatingCaseId.value = escalationCase.id;
            },
            onFinish: () => {
                investigatingCaseId.value = null;
            },
        },
    );
}

function paginationLabel(label: string): string {
    return label.replace('&laquo;', '‹').replace('&raquo;', '›');
}
</script>

<template>
    <div class="space-y-6 p-4 sm:p-6">
        <Head title="Subtitle escalations" />
        <SubtitleTabs
            active="escalations"
            :connections="connections"
            :selected-connection-id="selected_connection_id"
        />

        <section
            class="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <div class="flex items-center gap-2">
                    <AlertTriangle class="size-5 text-warning" />
                    <h2 class="font-semibold">Subtitle escalations</h2>
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    Cases that exhausted Bazarr searching or need a recorded
                    review.
                </p>
            </div>

            <div
                v-if="can_filter"
                class="grid gap-3 sm:grid-cols-2"
                data-test="escalation-filters"
            >
                <label class="text-sm font-medium">
                    Connection
                    <select
                        data-test="escalation-connection-filter"
                        class="mt-1 h-9 min-w-48 rounded-md border border-input bg-background px-3"
                        :value="filters.connection ?? ''"
                        @change="updateConnection"
                    >
                        <option
                            v-for="connection in filter_options.connections"
                            :key="connection.id"
                            :value="connection.id"
                        >
                            {{ connection.name }}
                        </option>
                    </select>
                </label>
                <label class="text-sm font-medium">
                    Status
                    <select
                        data-test="escalation-status-filter"
                        class="mt-1 h-9 min-w-48 rounded-md border border-input bg-background px-3 capitalize"
                        :value="filters.status"
                        @change="updateStatus"
                    >
                        <option value="">All escalation states</option>
                        <option
                            v-for="status in filter_options.statuses"
                            :key="status.value"
                            :value="status.value"
                        >
                            {{ status.label }}
                        </option>
                    </select>
                </label>
            </div>
        </section>

        <div
            v-if="requires_connection_selection"
            class="rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
        >
            Select a Bazarr connection to inspect escalation cases.
        </div>

        <section
            v-else-if="cases.data.length === 0"
            class="rounded-xl border border-border bg-card p-10 text-center"
        >
            <Search class="mx-auto mb-3 size-6 text-muted-foreground" />
            <p class="font-medium">No subtitle escalations found</p>
            <p class="text-sm text-muted-foreground">
                Cases will appear after Bazarr searches are exhausted.
            </p>
        </section>

        <div v-else class="space-y-3">
            <article
                v-for="escalationCase in cases.data"
                :key="escalationCase.id"
                class="grid gap-4 rounded-xl border border-border bg-card p-5 lg:grid-cols-[1fr_auto] lg:items-center"
                :data-test="`subtitle-escalation-${escalationCase.id}`"
            >
                <div class="min-w-0 space-y-3">
                    <div
                        class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div>
                            <h3 class="truncate font-semibold">
                                {{ escalationCase.display_name }}
                            </h3>
                            <p class="text-xs text-muted-foreground capitalize">
                                {{ escalationCase.scope }} ·
                                {{ escalationCase.media_type }} ·
                                {{ escalationCase.source_connection }} →
                                {{ escalationCase.bazarr_connection }}
                            </p>
                        </div>
                        <StatusPill :status="escalationCase.status" />
                    </div>

                    <div
                        class="grid gap-2 text-sm sm:grid-cols-2 xl:grid-cols-4"
                    >
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Missing languages
                            </p>
                            <p>
                                {{
                                    escalationCase.missing_languages.join(
                                        ', ',
                                    ) || 'Unknown'
                                }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Probes</p>
                            <p>{{ escalationCase.probe_count }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                First seen
                            </p>
                            <TimeStamp :iso="escalationCase.first_seen_at" />
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Last probe
                            </p>
                            <TimeStamp
                                v-if="escalationCase.last_probe_at"
                                :iso="escalationCase.last_probe_at"
                            />
                            <p v-else>Not probed</p>
                        </div>
                    </div>

                    <div class="space-y-1 text-xs text-muted-foreground">
                        <p :data-test="`advisor-summary-${escalationCase.id}`">
                            {{ advisorSummary(escalationCase) }}
                        </p>
                        <p :data-test="`advisor-action-${escalationCase.id}`">
                            {{ actionSummary(escalationCase) }}
                        </p>
                    </div>
                </div>

                <Button
                    v-if="can_investigate"
                    type="button"
                    variant="outline"
                    :disabled="
                        !canRequestAdvisor(escalationCase) ||
                        investigatingCaseId === escalationCase.id
                    "
                    :data-test="`investigate-subtitle-case-${escalationCase.id}`"
                    @click="investigate(escalationCase)"
                >
                    <LoaderCircle
                        v-if="investigatingCaseId === escalationCase.id"
                        class="size-4 animate-spin"
                    />
                    <Sparkles v-else class="size-4" />
                    {{ advisorButtonLabel(escalationCase) }}
                </Button>
            </article>
        </div>

        <nav
            v-if="cases.links.length > 3"
            aria-label="Escalation pages"
            class="flex flex-wrap justify-center gap-1"
        >
            <Link
                v-for="(link, index) in cases.links"
                :key="`${link.label}-${index}`"
                :href="link.url ?? '#'"
                preserve-scroll
                class="rounded-md border border-border px-3 py-2 text-sm"
                :class="{
                    'bg-primary text-primary-foreground': link.active,
                    'pointer-events-none opacity-50': !link.url,
                }"
            >
                {{ paginationLabel(link.label) }}
            </Link>
        </nav>
    </div>
</template>
