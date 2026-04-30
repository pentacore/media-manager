<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Inbox,
    RefreshCcw,
    Settings as SettingsIcon,
    Sparkles,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import ActionRequestController from '@/actions/App/Http/Controllers/Actions/ActionRequestController';
import ActionTypeConfigController from '@/actions/App/Http/Controllers/Actions/ActionTypeConfigController';
import { Field, InitialsAvatar, StatusPill, SvcChip } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { useRealtimeList } from '@/composables/useRealtimeList';
import { useWebSocket } from '@/composables/useWebSocket';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { ActionRequestResource } from '@/typefinder/resources/ActionRequestResource';

type ActionRequestRow = ActionRequestResource;

interface PaginatorLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatorMeta {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

const props = defineProps<{
    requests: {
        data: ActionRequestRow[];
        links: PaginatorLink[];
        meta: PaginatorMeta;
    };
    statusCounts: Record<string, number>;
    filters: { status: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Overview', href: dashboard().url },
            {
                title: 'Action Queue',
                href: ActionRequestController.index.url(),
            },
        ],
    },
});

const page = usePage();

const isAdmin = computed(() => {
    const role = page.props.auth.user?.role;

    if (!role) {
        return false;
    }

    const value = typeof role === 'string' ? role : role.value;

    return value === 'admin';
});

const hasFilter = computed(() => props.filters.status !== '');
const onFirstPage = computed(() => props.requests.meta.current_page === 1);
const merge = computed(() => !hasFilter.value && onFirstPage.value);

const ACTIONS_CHANNEL = 'members.actions';

const {
    items: liveRequests,
    staleCount,
    pause,
    resume,
    subscribe: subscribeCreated,
} = useRealtimeList<ActionRequestRow>({
    channel: ACTIONS_CHANNEL,
    event: 'ActionRequestCreated',
    keyField: 'id',
    initial: props.requests.data,
    cap: props.requests.meta.per_page,
});

watch(
    merge,
    (canMerge) => {
        if (canMerge) {
            resume();
        } else {
            pause();
        }
    },
    { immediate: true },
);

const visibleRequests = computed<ActionRequestRow[]>(() =>
    merge.value ? liveRequests.value : props.requests.data,
);

const selectedId = ref<number | null>(visibleRequests.value[0]?.id ?? null);

watch(visibleRequests, (rows) => {
    if (
        selectedId.value === null ||
        !rows.some((row) => row.id === selectedId.value)
    ) {
        selectedId.value = rows[0]?.id ?? null;
    }
});

const selected = computed<ActionRequestRow | null>(
    () =>
        visibleRequests.value.find((row) => row.id === selectedId.value) ??
        null,
);

const { privateChannel, leaveChannel } = useWebSocket();

interface StatusChangePayload {
    id: number;
    status: ActionRequestRow['status'];
    result: { success: boolean | null; reason: string | null };
    updated_at: string;
}

function applyStatusChange(payload: StatusChangePayload): void {
    const target = liveRequests.value.find((row) => row.id === payload.id);

    if (target) {
        target.status = payload.status;
        target.result = payload.result;
    }
}

// Tab counts depend on a global aggregate that the in-memory liveRequests
// list can't compute (it's capped at one page). Reload the prop on every
// status-change event so the strip stays in sync with the DB.
let statusCountsReloadTimer: ReturnType<typeof setTimeout> | null = null;
function scheduleStatusCountsReload(): void {
    if (statusCountsReloadTimer !== null) {
        return;
    }

    statusCountsReloadTimer = setTimeout(() => {
        statusCountsReloadTimer = null;
        router.reload({ only: ['statusCounts'] });
    }, 500);
}

onMounted(() => {
    subscribeCreated();

    privateChannel(ACTIONS_CHANNEL)
        .listen(
            '.ActionRequestStatusChanged',
            (event: StatusChangePayload) => {
                applyStatusChange(event);
                scheduleStatusCountsReload();
            },
        )
        .listen('.ActionRequestCreated', () => scheduleStatusCountsReload());
});

onUnmounted(() => {
    leaveChannel(ACTIONS_CHANNEL);
    if (statusCountsReloadTimer !== null) {
        clearTimeout(statusCountsReloadTimer);
        statusCountsReloadTimer = null;
    }
});

const TABS: { id: string; label: string }[] = [
    { id: 'all', label: 'All' },
    { id: 'pending', label: 'Pending' },
    { id: 'approved', label: 'Approved' },
    { id: 'executing', label: 'Executing' },
    { id: 'completed', label: 'Completed' },
    { id: 'failed', label: 'Failed' },
    { id: 'rejected', label: 'Rejected' },
];

function currentFilter(): string {
    return props.filters.status === '' ? 'all' : props.filters.status;
}

function setFilter(id: string): void {
    router.get(
        ActionRequestController.index.url(),
        id === 'all' ? {} : { status: id },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const refreshing = ref(false);

function refresh(): void {
    if (refreshing.value) {
        return;
    }

    refreshing.value = true;
    router.reload({
        only: ['requests'],
        onSuccess: () => resume(),
        onFinish: () => {
            refreshing.value = false;
        },
    });
}

function formatRelative(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const ms = Date.now() - new Date(iso).getTime();
    const m = Math.floor(ms / 60_000);

    if (m < 1) {
        return 'just now';
    }

    if (m < 60) {
        return `${m}m ago`;
    }

    const h = Math.floor(m / 60);

    if (h < 24) {
        return `${h}h ago`;
    }

    return `${Math.floor(h / 24)}d ago`;
}

function isDestructive(type: string | null | undefined): boolean {
    return /delete|remove|destroy/.test(type ?? '');
}

function svcId(name: string | null | undefined): string {
    if (!name) {
        return '';
    }

    const t = name.toLowerCase();

    if (t.includes('jellyseerr') || t.includes('seerr')) {
        return 'seerr';
    }

    if (t.includes('sonarr')) {
        return 'sonarr';
    }

    if (t.includes('radarr')) {
        return 'radarr';
    }

    if (t.includes('emby')) {
        return 'emby';
    }

    if (t.includes('prowlarr')) {
        return 'prowlarr';
    }

    return t;
}

function approve(id: number): void {
    router.post(
        ActionRequestController.approve.url(id),
        {},
        { preserveScroll: true },
    );
}

function reject(id: number): void {
    router.post(
        ActionRequestController.reject.url(id),
        {},
        { preserveScroll: true },
    );
}

function retry(id: number): void {
    router.post(
        ActionRequestController.retry.url(id),
        {},
        { preserveScroll: true },
    );
}

function goToPage(url: string | null): void {
    if (!url) {
        return;
    }

    router.get(url, {}, { preserveState: true, preserveScroll: true });
}

function payloadTitle(row: ActionRequestRow): string {
    const t = row.payload?.['title'] ?? row.payload?.['name'];

    return typeof t === 'string' && t.length > 0
        ? t
        : `${row.type.replace(/_/g, ' ')}`;
}

function payloadDetail(row: ActionRequestRow): string {
    const detail = row.payload?.['detail'] ?? row.payload?.['summary'];

    return typeof detail === 'string' ? detail : '';
}

function statusCount(id: string): number {
    if (id === 'all') {
        return Object.values(props.statusCounts).reduce(
            (sum, n) => sum + n,
            0,
        );
    }

    return props.statusCounts[id] ?? 0;
}

function pipelineState(
    row: ActionRequestRow,
    stage: 'created' | 'approved' | 'executing' | 'done',
): 'done' | 'failed' | 'active' | 'pending' {
    const s = row.status;

    if (stage === 'created') {
        return 'done';
    }

    if (stage === 'approved') {
        return s === 'pending'
            ? 'pending'
            : s === 'rejected'
              ? 'failed'
              : 'done';
    }

    if (stage === 'executing') {
        return ['executing', 'completed', 'failed'].includes(s)
            ? 'done'
            : 'pending';
    }

    if (s === 'completed') {
        return 'done';
    }

    if (s === 'failed') {
        return 'failed';
    }

    return 'pending';
}
</script>

<template>
    <Head title="Action Queue" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero -->
        <div class="flex items-end justify-between gap-3">
            <div>
                <h1 class="text-[22px] font-semibold tracking-tight">
                    Action queue
                </h1>
                <p class="mt-1 text-[13px] text-muted-foreground">
                    <span class="font-mono-tabular">ActionRequest</span>
                    state machine — pending → approved → executing →
                    completed/failed
                </p>
            </div>
            <div class="flex items-center gap-2">
                <div
                    v-if="staleCount > 0"
                    class="flex items-center gap-2 rounded-md border border-accent/40 bg-accent/10 px-3 py-1 text-sm text-accent"
                >
                    <Sparkles class="size-4" />
                    <span>{{ staleCount }} new</span>
                    <Button
                        size="sm"
                        variant="ghost"
                        class="h-6 px-2 text-accent hover:bg-accent/20"
                        @click="refresh"
                    >
                        Refresh
                    </Button>
                </div>
                <Link
                    v-if="isAdmin"
                    :href="ActionTypeConfigController.index.url()"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md border border-border bg-card px-2 text-xs font-medium text-foreground transition-colors hover:bg-bg-hover"
                >
                    <SettingsIcon class="size-3.5" />Approval rules
                </Link>
                <Button
                    variant="outline"
                    size="sm"
                    class="h-7 gap-1.5 px-2 text-xs"
                    :disabled="refreshing"
                    @click="refresh"
                >
                    <RefreshCcw
                        class="size-3.5"
                        :class="{ 'animate-spin': refreshing }"
                    />Refresh
                </Button>
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex flex-wrap items-center gap-1.5">
            <button
                v-for="tab in TABS"
                :key="tab.id"
                type="button"
                :class="
                    cn(
                        'inline-flex h-7 items-center gap-1.5 rounded-md px-2.5 text-xs font-medium transition-colors',
                        currentFilter() === tab.id
                            ? 'bg-accent text-accent-foreground'
                            : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                    )
                "
                @click="setFilter(tab.id)"
            >
                {{ tab.label }}
                <span
                    v-if="tab.id === 'all' || statusCount(tab.id) > 0"
                    class="font-mono-tabular text-[11px] opacity-70"
                >
                    {{
                        tab.id === 'all'
                            ? requests.meta.total
                            : statusCount(tab.id)
                    }}
                </span>
            </button>
        </div>

        <!-- Table + Detail -->
        <div class="grid gap-4 lg:grid-cols-[1.6fr_1fr] lg:items-start">
            <div
                class="overflow-hidden rounded-xl border border-border bg-card"
            >
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th
                                class="w-6 border-b border-border bg-card px-3 py-2"
                            />
                            <th
                                class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Action
                            </th>
                            <th
                                class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Service
                            </th>
                            <th
                                class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Trigger
                            </th>
                            <th
                                class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Status
                            </th>
                            <th
                                class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                Age
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in visibleRequests"
                            :key="row.id"
                            :class="
                                cn(
                                    'border-b border-border transition-colors hover:bg-bg-hover',
                                    selectedId === row.id && 'bg-accent/8',
                                )
                            "
                            @click="selectedId = row.id"
                        >
                            <td class="px-3 py-2.5 align-middle">
                                <span
                                    :class="
                                        cn(
                                            'inline-block h-5 w-1 rounded-sm',
                                            isDestructive(row.type)
                                                ? 'bg-destructive'
                                                : 'bg-info',
                                        )
                                    "
                                />
                            </td>
                            <td class="px-3 py-2.5 align-middle">
                                <div class="font-medium">
                                    {{ payloadTitle(row) }}
                                </div>
                                <div
                                    class="font-mono-tabular text-[11px] text-fg-subtle"
                                >
                                    act_{{ row.id }} · {{ row.type }}
                                </div>
                            </td>
                            <td class="px-3 py-2.5 align-middle">
                                <SvcChip
                                    :id="svcId(row.target_service)"
                                    :label="row.target_service"
                                />
                            </td>
                            <td class="px-3 py-2.5 align-middle">
                                <span
                                    class="font-mono-tabular text-[11.5px] text-muted-foreground"
                                >
                                    {{ row.webhook_source ?? 'manual' }}
                                </span>
                            </td>
                            <td class="px-3 py-2.5 align-middle">
                                <StatusPill :status="row.status" />
                            </td>
                            <td
                                class="font-mono-tabular px-3 py-2.5 align-middle text-[11.5px] text-fg-subtle"
                            >
                                {{ formatRelative(row.created_at) }}
                            </td>
                        </tr>
                        <tr v-if="visibleRequests.length === 0">
                            <td
                                colspan="6"
                                class="px-3 py-12 text-center text-sm text-fg-subtle"
                            >
                                <div class="flex flex-col items-center gap-2">
                                    <Inbox class="size-5" />
                                    No action requests in this view.
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Detail panel -->
            <aside class="lg:sticky lg:top-16">
                <div
                    v-if="!selected"
                    class="flex flex-col items-center gap-2 rounded-xl border border-border bg-card p-9 text-fg-subtle"
                >
                    <Inbox class="size-5" />
                    <span class="text-sm">Select an action to inspect.</span>
                </div>
                <div
                    v-else
                    class="overflow-hidden rounded-xl border border-border bg-card"
                >
                    <div
                        class="flex flex-col gap-1.5 border-b border-border p-4"
                    >
                        <div class="flex items-center justify-between">
                            <span
                                class="font-mono-tabular text-[11px] text-fg-subtle"
                                >act_{{ selected.id }}</span
                            >
                            <StatusPill :status="selected.status" />
                        </div>
                        <h2
                            class="font-serif text-[22px] leading-tight text-foreground italic"
                        >
                            {{ payloadTitle(selected) }}
                        </h2>
                        <div class="flex items-center gap-2.5">
                            <SvcChip
                                :id="svcId(selected.target_service)"
                                :label="selected.target_service"
                            />
                            <span
                                :class="
                                    cn(
                                        'font-mono-tabular rounded border px-1.5 py-0.5 text-[11px]',
                                        isDestructive(selected.type)
                                            ? 'border-destructive/35 text-destructive'
                                            : 'border-border text-fg-subtle',
                                    )
                                "
                            >
                                {{ selected.type }}
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3.5 p-4">
                        <Field label="Trigger">
                            {{ selected.webhook_source ?? 'Manual' }}
                        </Field>
                        <Field label="Source → target">
                            <span
                                class="font-mono-tabular text-[12px] text-muted-foreground"
                            >
                                {{ selected.source_service }} →
                                {{ selected.target_service }}
                            </span>
                        </Field>
                        <Field label="Approved by">
                            <div class="flex items-center gap-2">
                                <InitialsAvatar
                                    :name="selected.approved_by ?? 'system'"
                                    :size="20"
                                />
                                <span class="text-[13px]">{{
                                    selected.approved_by ?? 'system'
                                }}</span>
                            </div>
                        </Field>
                        <Field label="Created">
                            {{ formatRelative(selected.created_at) }}
                        </Field>
                        <Field v-if="payloadDetail(selected)" label="Detail">
                            <span class="text-[13px] text-muted-foreground">{{
                                payloadDetail(selected)
                            }}</span>
                        </Field>

                        <div
                            v-if="isDestructive(selected.type)"
                            class="flex gap-2.5 rounded-lg border border-destructive/25 bg-destructive/10 p-3"
                        >
                            <AlertTriangle
                                class="mt-0.5 size-4 shrink-0 text-destructive"
                            />
                            <div class="text-[12.5px] text-muted-foreground">
                                This action is
                                <span class="font-semibold text-destructive"
                                    >destructive</span
                                >. Approval queues an
                                <span class="font-mono-tabular text-[11.5px]"
                                    >ExecuteActionRequest</span
                                >
                                job. Files on disk will be removed.
                            </div>
                        </div>

                        <Field label="Pipeline">
                            <ol
                                class="font-mono-tabular flex flex-col gap-1 rounded-md border border-border bg-bg-elev p-2.5 text-[11px]"
                            >
                                <li
                                    v-for="stage in [
                                        { id: 'created', label: 'created' },
                                        { id: 'approved', label: 'approved' },
                                        {
                                            id: 'executing',
                                            label: 'executing',
                                        },
                                        { id: 'done', label: 'completed' },
                                    ]"
                                    :key="stage.id"
                                    :class="
                                        cn(
                                            'flex items-center gap-2',
                                            pipelineState(
                                                selected,
                                                stage.id as
                                                    | 'created'
                                                    | 'approved'
                                                    | 'executing'
                                                    | 'done',
                                            ) === 'done'
                                                ? 'text-accent'
                                                : pipelineState(
                                                        selected,
                                                        stage.id as
                                                            | 'created'
                                                            | 'approved'
                                                            | 'executing'
                                                            | 'done',
                                                    ) === 'failed'
                                                  ? 'text-destructive'
                                                  : 'text-fg-subtle',
                                        )
                                    "
                                >
                                    <span>
                                        <template
                                            v-if="
                                                pipelineState(
                                                    selected,
                                                    stage.id as
                                                        | 'created'
                                                        | 'approved'
                                                        | 'executing'
                                                        | 'done',
                                                ) === 'done'
                                            "
                                            >●</template
                                        >
                                        <template
                                            v-else-if="
                                                pipelineState(
                                                    selected,
                                                    stage.id as
                                                        | 'created'
                                                        | 'approved'
                                                        | 'executing'
                                                        | 'done',
                                                ) === 'failed'
                                            "
                                            >✕</template
                                        >
                                        <template v-else>○</template>
                                    </span>
                                    <span>{{
                                        stage.id === 'done' &&
                                        selected.status === 'failed'
                                            ? 'failed'
                                            : stage.label
                                    }}</span>
                                </li>
                            </ol>
                        </Field>

                        <div
                            v-if="selected.status === 'pending'"
                            class="mt-1 flex gap-2"
                        >
                            <Button
                                class="flex-1"
                                @click="approve(selected.id)"
                            >
                                Approve &amp; execute
                            </Button>
                            <Button
                                variant="destructive"
                                @click="reject(selected.id)"
                            >
                                Decline
                            </Button>
                        </div>
                        <Button
                            v-else-if="selected.status === 'failed' && isAdmin"
                            variant="outline"
                            @click="retry(selected.id)"
                        >
                            Retry
                        </Button>
                    </div>
                </div>
            </aside>
        </div>

        <!-- Pagination -->
        <div
            v-if="requests.links.length > 3"
            class="flex flex-wrap items-center gap-2"
        >
            <Button
                v-for="(link, index) in requests.links"
                :key="index"
                variant="outline"
                size="sm"
                :disabled="!link.url"
                :class="link.active ? 'bg-accent text-accent-foreground' : ''"
                @click="goToPage(link.url)"
            >
                <span v-html="link.label" />
            </Button>
        </div>
    </div>
</template>
