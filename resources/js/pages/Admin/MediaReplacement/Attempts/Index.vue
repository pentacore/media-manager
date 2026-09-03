<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Eye, RefreshCcw } from '@lucide/vue';
import { onMounted, onUnmounted, ref, watch } from 'vue';
import ActionRequestController from '@/actions/App/Http/Controllers/Actions/ActionRequestController';
import MediaReplacementAttemptController from '@/actions/App/Http/Controllers/Admin/MediaReplacementAttemptController';
import MediaReplacementSettingsController from '@/actions/App/Http/Controllers/Admin/MediaReplacementSettingsController';
import MediaReplacementTabs from '@/components/media-replacement/MediaReplacementTabs.vue';
import { Pill, SvcChip, TimeStamp, Toggle } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useRealtimeReload } from '@/composables/useRealtimeReload';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';

interface AttemptRow {
    id: number;
    action_request_id: number;
    action_request_status: string | null;
    status: string;
    failure_reason: string | null;
    scope: string;
    service_name: string | null;
    service_type: string | null;
    display_name: string | null;
    season_number: number | null;
    episode_numbers: number[];
    candidate_title: string | null;
    candidate_release_group: string | null;
    candidate_quality: string | null;
    required_languages: string[];
    verification: {
        subtitles_checked: boolean;
        found: string[];
        missing: string[];
    } | null;
    monitoring_suspended: boolean;
    acknowledged_at: string | null;
    started_at: string | null;
    completed_at: string | null;
    created_at: string | null;
}

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

interface EnumOption {
    value: string;
    label: string;
}

interface ServiceOption {
    id: number;
    name: string;
    type: string;
}

const props = defineProps<{
    attempts: {
        data: AttemptRow[];
        links: PaginatorLink[];
        meta: PaginatorMeta;
    };
    filters: {
        status: string | null;
        scope: string | null;
        service_id: number | null;
        search: string;
        unacknowledged: boolean;
    };
    filterOptions: {
        statuses: EnumOption[];
        scopes: EnumOption[];
        services: ServiceOption[];
    };
    statusCounts: Record<string, number>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            {
                title: 'Media Replacement',
                href: MediaReplacementSettingsController.index.url(),
            },
            {
                title: 'Attempts',
                href: MediaReplacementAttemptController.index.url(),
            },
        ],
    },
});

type PillVariant = 'default' | 'ok' | 'warn' | 'danger' | 'info';

const STATUS_VARIANTS: Record<string, PillVariant> = {
    requested: 'info',
    downloading: 'info',
    imported: 'default',
    verified: 'ok',
    failed: 'danger',
    needs_attention: 'warn',
};

function statusVariant(status: string): PillVariant {
    return STATUS_VARIANTS[status] ?? 'default';
}

function statusLabel(status: string): string {
    return (
        props.filterOptions.statuses.find((option) => option.value === status)
            ?.label ?? status.replace(/_/g, ' ')
    );
}

function episodeCode(row: AttemptRow): string {
    if (row.season_number === null || row.episode_numbers.length === 0) {
        return '';
    }

    const season = String(row.season_number).padStart(2, '0');
    const episodes = row.episode_numbers
        .map((episode) => `E${String(episode).padStart(2, '0')}`)
        .join('');

    return `S${season}${episodes}`;
}

function applyFilters(next: Partial<typeof props.filters>): void {
    // A pending debounced search must not fire after this visit: its `merged`
    // would be rebuilt from the pre-visit `props.filters` and would drop the
    // filter applied here (and `replace: true` would rewrite the URL to it).
    if (searchTimer) {
        clearTimeout(searchTimer);
        searchTimer = null;
    }

    const merged = { ...props.filters, ...next };
    const query: Record<string, string | number> = {};

    if (merged.status) {
        query.status = merged.status;
    }

    if (merged.scope) {
        query.scope = merged.scope;
    }

    if (merged.service_id) {
        query.service_id = merged.service_id;
    }

    if (merged.search) {
        query.search = merged.search;
    }

    if (merged.unacknowledged) {
        query.unacknowledged = 1;
    }

    router.get(MediaReplacementAttemptController.index.url(), query, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function setStatus(value: string): void {
    // Needs attention defaults to hiding acknowledged rows; any other status
    // has no acknowledgement to hide.
    applyFilters({
        status: value === 'all' ? null : value,
        unacknowledged: value === 'needs_attention',
    });
}

function setScope(value: string): void {
    applyFilters({ scope: value === 'all' ? null : value });
}

function setService(value: string): void {
    applyFilters({ service_id: value === 'all' ? null : Number(value) });
}

const search = ref(props.filters.search);
let searchTimer: ReturnType<typeof setTimeout> | null = null;

watch(search, (value) => {
    if (searchTimer) {
        clearTimeout(searchTimer);
    }

    searchTimer = setTimeout(() => {
        searchTimer = null;

        if (value.trim() !== props.filters.search) {
            applyFilters({ search: value.trim() });
        }
    }, 300);
});

watch(
    () => props.filters.search,
    (value) => {
        search.value = value;
    },
);

function goToPage(url: string | null): void {
    if (!url) {
        return;
    }

    router.get(url, {}, { preserveScroll: true });
}

function canRetry(row: AttemptRow): boolean {
    return row.status === 'failed' && row.action_request_status === 'failed';
}

function retry(row: AttemptRow): void {
    router.post(
        ActionRequestController.retry.url(row.action_request_id),
        {},
        { preserveScroll: true },
    );
}

const STATUS_ORDER = [
    'requested',
    'downloading',
    'imported',
    'verified',
    'failed',
    'needs_attention',
];

// Summed over the statuses only — `statusCounts` also carries the derived
// `attention_unacknowledged` key, which would double-count. Kept independent
// of the active filters so the tile agrees with its siblings.
function totalAttempts(): number {
    return STATUS_ORDER.reduce(
        (sum, status) => sum + (props.statusCounts[status] ?? 0),
        0,
    );
}

const { subscribe } = useRealtimeReload({
    channel: 'admin.media-replacement',
    event: 'MediaReplacementAttemptChanged',
    only: ['attempts', 'statusCounts'],
});

onMounted(subscribe);

// `setTimeout` isn't bound to the effect scope, so a pending search would
// still navigate back to this list after the user has left for a detail page.
onUnmounted(() => {
    if (searchTimer) {
        clearTimeout(searchTimer);
        searchTimer = null;
    }
});
</script>

<template>
    <Head title="Replacement attempts" />

    <div class="flex flex-col gap-4 p-5">
        <div>
            <div class="mb-1.5 text-[13px] text-muted-foreground">
                Admin <span class="text-fg-subtle">/</span> Media replacement
                <span class="text-fg-subtle">/</span> Attempts
            </div>
            <h1 class="text-[22px] leading-tight font-semibold tracking-tight">
                Replacement attempts
            </h1>
            <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                Every grab-before-delete replacement: what was targeted, which
                release was chosen, and how it ended. Rows update live as
                downloads land.
            </p>
        </div>

        <MediaReplacementTabs
            :attention-count="statusCounts.attention_unacknowledged ?? 0"
        />

        <div class="flex flex-wrap gap-2">
            <button
                type="button"
                data-status-count="all"
                :class="
                    cn(
                        'rounded-lg border px-3 py-1.5 text-left transition-colors',
                        filters.status === null
                            ? 'border-accent bg-accent/10'
                            : 'border-border bg-card hover:bg-bg-hover',
                    )
                "
                @click="setStatus('all')"
            >
                <div
                    class="text-[10.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                >
                    All
                </div>
                <div class="font-mono-tabular text-[15px]">
                    {{ totalAttempts() }}
                </div>
            </button>
            <button
                v-for="status in STATUS_ORDER"
                :key="status"
                type="button"
                :data-status-count="status"
                :class="
                    cn(
                        'rounded-lg border px-3 py-1.5 text-left transition-colors',
                        filters.status === status
                            ? 'border-accent bg-accent/10'
                            : 'border-border bg-card hover:bg-bg-hover',
                    )
                "
                @click="setStatus(status)"
            >
                <div
                    class="text-[10.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                >
                    {{ statusLabel(status) }}
                </div>
                <div class="font-mono-tabular text-[15px]">
                    {{ statusCounts[status] ?? 0 }}
                </div>
            </button>
        </div>

        <div
            class="flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card p-3"
        >
            <span
                class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
            >
                Filter
            </span>
            <Select
                :model-value="filters.scope ?? 'all'"
                @update:model-value="
                    (v) => typeof v === 'string' && setScope(v)
                "
            >
                <SelectTrigger class="h-7 w-36 text-xs">
                    <SelectValue placeholder="Scope" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All scopes</SelectItem>
                    <SelectItem
                        v-for="option in filterOptions.scopes"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Select
                :model-value="
                    filters.service_id ? String(filters.service_id) : 'all'
                "
                @update:model-value="
                    (v) => typeof v === 'string' && setService(v)
                "
            >
                <SelectTrigger class="h-7 w-40 text-xs">
                    <SelectValue placeholder="Service" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All services</SelectItem>
                    <SelectItem
                        v-for="service in filterOptions.services"
                        :key="service.id"
                        :value="String(service.id)"
                    >
                        {{ service.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Input
                v-model="search"
                data-attempts-search
                placeholder="Search target or release…"
                class="h-7 w-64 text-xs"
            />
            <Toggle
                v-if="filters.status === 'needs_attention'"
                :model-value="filters.unacknowledged"
                label="Hide acknowledged"
                @update:model-value="(v) => applyFilters({ unacknowledged: v })"
            />
            <span
                class="font-mono-tabular ml-auto text-[11.5px] text-muted-foreground"
            >
                {{ attempts.meta.total }} attempts
            </span>
        </div>

        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th
                                v-for="h in [
                                    'Started',
                                    'Target',
                                    'Candidate',
                                    'Status',
                                    'Subtitles',
                                    'Completed',
                                    '',
                                ]"
                                :key="h"
                                class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                {{ h }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in attempts.data"
                            :key="row.id"
                            :data-attempt-row="row.id"
                            :data-attempt-status="row.status"
                            class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                        >
                            <td class="px-3 py-2.5 whitespace-nowrap">
                                <TimeStamp
                                    :iso="row.started_at ?? row.created_at"
                                    class="text-[11.5px] text-fg-subtle"
                                />
                            </td>
                            <td class="px-3 py-2.5">
                                <div class="flex items-center gap-2">
                                    <SvcChip
                                        v-if="row.service_type"
                                        :id="row.service_type"
                                    />
                                    <span class="font-medium">
                                        {{ row.display_name ?? '—' }}
                                    </span>
                                    <span
                                        v-if="episodeCode(row)"
                                        class="font-mono-tabular text-[11.5px] text-muted-foreground"
                                    >
                                        {{ episodeCode(row) }}
                                    </span>
                                    <Pill variant="default">
                                        {{ row.scope }}
                                    </Pill>
                                </div>
                            </td>
                            <td class="px-3 py-2.5">
                                <div
                                    class="font-mono-tabular max-w-[360px] truncate text-[12px]"
                                    :title="row.candidate_title ?? ''"
                                >
                                    {{ row.candidate_title ?? '—' }}
                                </div>
                                <div
                                    class="text-[11.5px] text-muted-foreground"
                                >
                                    {{
                                        [
                                            row.candidate_release_group,
                                            row.candidate_quality,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ') || '—'
                                    }}
                                </div>
                            </td>
                            <td class="px-3 py-2.5">
                                <div class="flex flex-col gap-1">
                                    <Pill
                                        :variant="statusVariant(row.status)"
                                        dot
                                    >
                                        {{ statusLabel(row.status) }}
                                    </Pill>
                                    <span
                                        v-if="row.failure_reason"
                                        class="font-mono-tabular text-[11px] text-muted-foreground"
                                    >
                                        {{ row.failure_reason }}
                                    </span>
                                    <span
                                        v-if="row.acknowledged_at"
                                        class="text-[11px] text-fg-subtle"
                                    >
                                        acknowledged
                                    </span>
                                    <span
                                        v-if="row.monitoring_suspended"
                                        class="text-[11px] text-warning"
                                    >
                                        monitoring still suspended
                                    </span>
                                </div>
                            </td>
                            <td class="px-3 py-2.5 text-[12px]">
                                <template v-if="row.verification">
                                    <span
                                        v-if="
                                            !row.verification.subtitles_checked
                                        "
                                        class="text-fg-subtle"
                                        >not checked</span
                                    >
                                    <template v-else>
                                        <span class="text-success">{{
                                            row.verification.found.join(', ') ||
                                            '—'
                                        }}</span>
                                        <span
                                            v-if="
                                                row.verification.missing.length
                                            "
                                            class="text-destructive"
                                        >
                                            · missing
                                            {{
                                                row.verification.missing.join(
                                                    ', ',
                                                )
                                            }}
                                        </span>
                                    </template>
                                </template>
                                <span v-else class="text-fg-subtle">
                                    wants
                                    {{
                                        row.required_languages.join(', ') || '—'
                                    }}
                                </span>
                            </td>
                            <td class="px-3 py-2.5 whitespace-nowrap">
                                <TimeStamp
                                    :iso="row.completed_at"
                                    class="text-[11.5px] text-fg-subtle"
                                />
                            </td>
                            <td
                                class="px-3 py-2.5 text-right whitespace-nowrap"
                            >
                                <Button
                                    v-if="canRetry(row)"
                                    variant="ghost"
                                    size="sm"
                                    class="h-7 px-2 text-xs"
                                    :data-attempt-retry="row.id"
                                    @click="retry(row)"
                                >
                                    <RefreshCcw class="size-3.5" />Retry
                                </Button>
                                <Link
                                    :href="
                                        MediaReplacementAttemptController.show.url(
                                            row.id,
                                        )
                                    "
                                    :data-attempt-view="row.id"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="h-7 px-2 text-xs"
                                    >
                                        <Eye class="size-3.5" />View
                                    </Button>
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="attempts.data.length === 0">
                            <td
                                colspan="7"
                                class="px-3 py-12 text-center text-sm text-fg-subtle"
                            >
                                No replacement attempts match these filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div
            v-if="attempts.meta.last_page > 1"
            class="flex flex-wrap items-center justify-between gap-2"
        >
            <p class="text-sm text-muted-foreground">
                Page {{ attempts.meta.current_page }} of
                {{ attempts.meta.last_page }} —
                <span class="font-mono-tabular">{{ attempts.meta.total }}</span>
                attempts
            </p>
            <div class="flex flex-wrap gap-1">
                <Button
                    v-for="link in attempts.links"
                    :key="link.label"
                    :variant="link.active ? 'default' : 'outline'"
                    size="sm"
                    :disabled="!link.url"
                    @click="goToPage(link.url)"
                >
                    <span v-html="link.label" />
                </Button>
            </div>
        </div>
    </div>
</template>
