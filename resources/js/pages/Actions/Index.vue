<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import ActionRequestController from '@/actions/App/Http/Controllers/Actions/ActionRequestController';
import {
    ActionBatchFilterChip,
    ActionQueueHeader,
    ActionQueuePagination,
    ActionRequestCards,
    ActionRequestDetail,
    ActionRequestSheet,
    ActionRequestTable,
    ActionStatusTabs,
    BulkRejectDialog,
    SubtitleAuditManualPicks,
    isUncertainOutcome,
    retryConfirmation,
} from '@/components/actions';
import type {
    ActionQueueRequests,
    ActionRequestRow,
} from '@/components/actions';
import type { SubtitleAuditBatchCard } from '@/components/ai/types';
import { BulkActionBar } from '@/components/bulk';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { useActionQueueLive } from '@/composables/useActionQueueLive';
import { useBulkSelection } from '@/composables/useBulkSelection';
import { useCan } from '@/composables/useCan';
import { useConfirm } from '@/composables/useConfirm';
import { focusAfterBulk, submitBulk } from '@/lib/bulk';
import { hasSubtitleAuditFollowUps } from '@/lib/subtitleAudit';
import { dashboard } from '@/routes';

const props = defineProps<{
    requests: ActionQueueRequests;
    statusCounts: Record<string, number>;
    filters: { status: string; subtitle_audit_batch: number | null };
    preselect: number | null;
    /** The batch the queue is filtered to; null without the filter or once it is gone. */
    subtitleAuditBatch?: SubtitleAuditBatchCard | null;
    /** The deep-linked request when this page does not list it. */
    preselectedRequest: ActionRequestRow | null;
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

const { isAdmin } = useCan();

const showSubtitleAuditFollowUps = computed(
    () =>
        props.subtitleAuditBatch != null &&
        hasSubtitleAuditFollowUps(props.subtitleAuditBatch.episodes),
);
const { confirm } = useConfirm();

const {
    visibleRequests,
    staleCount,
    refreshing,
    refresh,
    forgetLiveStatuses,
    subscribe: subscribeLive,
} = useActionQueueLive(props);

/**
 * A deep-linked request this page does not list (more than a page of newer
 * requests, or another tab): the server sends it on its own, so the sheet or
 * the pane can still show it.
 */
const offPageRequest = computed<ActionRequestRow | null>(() =>
    props.preselectedRequest !== null &&
    props.preselectedRequest.id === props.preselect
        ? props.preselectedRequest
        : null,
);

const selectedId = ref<number | null>(
    props.preselect !== null &&
        (visibleRequests.value.some((row) => row.id === props.preselect) ||
            offPageRequest.value !== null)
        ? props.preselect
        : (visibleRequests.value[0]?.id ?? null),
);

/** Below `sm` the selected action opens in a full-screen sheet. */
const sheetOpen = ref(false);

function openSheet(id: number): void {
    selectedId.value = id;
    sheetOpen.value = true;
}

/**
 * A deep link is one-shot: once the sheet closes, drop `?request=` from the
 * URL and the history entry (a client-side replace, no new entry), so a
 * reload or a back/forward return does not reopen it.
 */
function forgetDeepLink(): void {
    const url = new URL(window.location.href);

    if (!url.searchParams.has('request')) {
        return;
    }

    url.searchParams.delete('request');
    router.replace({
        url: `${url.pathname}${url.search}${url.hash}`,
        props: (current) => ({
            ...current,
            preselect: null,
            preselectedRequest: null,
        }),
        preserveState: true,
        preserveScroll: true,
    });
}

/** The sheet has closed at least once since this page mounted. */
let sheetClosed = false;

watch(sheetOpen, (isOpen) => {
    if (!isOpen) {
        sheetClosed = true;
        forgetDeepLink();
    }
});

// The forget is sticky: a visit already in flight when the sheet closed (the
// approve's redirect back to its Referer, a counts reload) lands on the old
// `?request=` URL and would put the deep link back. Drop it again.
const page = usePage();

watch(
    () => page.url,
    () => {
        if (sheetClosed && !sheetOpen.value) {
            forgetDeepLink();
        }
    },
);

watch(visibleRequests, (rows) => {
    if (
        selectedId.value === null ||
        (!rows.some((row) => row.id === selectedId.value) &&
            selectedId.value !== offPageRequest.value?.id)
    ) {
        // The phone sheet never swaps to another action under the user's
        // thumb: when its row leaves the list, the sheet closes.
        sheetOpen.value = false;
        selectedId.value = rows[0]?.id ?? null;
    }
});

// Once the deep link is forgotten, an off-page selection has nothing left to
// show: fall back to the first listed row, like a row that left the list.
watch(offPageRequest, (row) => {
    if (
        row === null &&
        selectedId.value !== null &&
        !visibleRequests.value.some((listed) => listed.id === selectedId.value)
    ) {
        selectedId.value = visibleRequests.value[0]?.id ?? null;
    }
});

const selected = computed<ActionRequestRow | null>(
    () =>
        visibleRequests.value.find((row) => row.id === selectedId.value) ??
        (offPageRequest.value?.id === selectedId.value
            ? offPageRequest.value
            : null),
);

const pendingOnPage = computed<number[]>(() =>
    visibleRequests.value
        .filter((row) => row.status === 'pending')
        .map((row) => row.id),
);

const {
    ids: bulkIds,
    count: bulkCount,
    isSelected: isBulkSelected,
    toggle: toggleBulk,
    setAll: setAllBulk,
    clear: clearBulk,
    retain: retainBulk,
} = useBulkSelection<number>([
    () => props.filters.status,
    () => props.filters.subtitle_audit_batch,
    () => props.requests.meta.current_page,
]);

// A row approved or rejected elsewhere (live status event, refresh) drops out.
watch(pendingOnPage, (ids) => retainBulk(ids));

const bulkSelectedOnPage = computed(
    () => pendingOnPage.value.filter((id) => isBulkSelected(id)).length,
);

const bulkBusy = ref(false);
const rejectDialogOpen = ref(false);
const rejectReason = ref('');

// A live status event can empty the selection while the reject dialog is
// open: close it rather than leave a "Reject 0 requests" confirm.
watch(bulkCount, (count) => {
    if (count === 0) {
        rejectDialogOpen.value = false;
    }
});

async function bulkReview(action: 'approve' | 'reject'): Promise<void> {
    if (bulkBusy.value || bulkIds.value.length === 0) {
        return;
    }

    bulkBusy.value = true;
    forgetLiveStatuses(bulkIds.value);
    const reason = rejectReason.value.trim();
    const summary = await submitBulk(ActionRequestController.bulk.url(), {
        ids: bulkIds.value,
        action,
        reason: action === 'reject' && reason !== '' ? reason : null,
    });
    bulkBusy.value = false;

    if (summary) {
        clearBulk();
        focusAfterBulk();
        rejectDialogOpen.value = false;
        rejectReason.value = '';
        // `nav` too: the bulk run is a JSON request, not a visit, so the
        // sidebar badges would otherwise wait for the broadcasts.
        router.reload({ only: ['requests', 'statusCounts', 'nav'] });
    }
}

/**
 * A notification deep link (`?request=<id>`) opens the action in the sheet on
 * a phone. Desktop selects it in the pane, as before. Client-only: SSR
 * renders the closed sheet either way.
 */
function openDeepLinkedSheet(): void {
    if (
        props.preselect !== null &&
        selectedId.value === props.preselect &&
        window.matchMedia('(max-width: 639.98px)').matches
    ) {
        sheetOpen.value = true;
    }
}

// Takes the live channel leases and visit listeners for this visit, then
// opens a deep-linked action. One hook: ActionQueueLiveListTest re-runs the
// page's only mounted hook to prove a second subscribe binds nothing twice.
onMounted(() => {
    subscribeLive();
    openDeepLinkedSheet();
});

function setFilter(id: string): void {
    router.get(
        ActionRequestController.index.url(),
        {
            ...(id === 'all' ? {} : { status: id }),
            ...(props.filters.subtitle_audit_batch === null
                ? {}
                : { subtitle_audit_batch: props.filters.subtitle_audit_batch }),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

/**
 * A review redirects back to the page it was sent from, which is the same URL,
 * so `replace` changes nothing there. It matters when the deep-linked sheet
 * closed while the visit was in flight: the redirect then lands on the old
 * `?request=` URL, and must not push a second history entry for it.
 */
const reviewVisitOptions = { preserveScroll: true, replace: true } as const;

function approve(id: number): void {
    forgetLiveStatuses([id]);
    router.post(
        ActionRequestController.approve.url(id),
        {},
        reviewVisitOptions,
    );
}

function reject(id: number): void {
    forgetLiveStatuses([id]);
    router.post(ActionRequestController.reject.url(id), {}, reviewVisitOptions);
}

async function retry(row: ActionRequestRow): Promise<void> {
    if (isUncertainOutcome(row.result)) {
        const confirmed = await confirm(retryConfirmation(row));

        // A live event may have moved the row on while the dialog was open,
        // and a `requests` reload may have swapped the row object, so read
        // the row the page lists now rather than the one clicked.
        const current = visibleRequests.value.find(
            (listed) => listed.id === row.id,
        );

        if (!confirmed || current?.status !== 'failed') {
            return;
        }
    }

    forgetLiveStatuses([row.id]);
    router.post(
        ActionRequestController.retry.url(row.id),
        {},
        reviewVisitOptions,
    );
}

function goToPage(url: string | null): void {
    if (!url) {
        return;
    }

    router.get(url, {}, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <Head title="Action Queue" />

    <div class="flex flex-col gap-4 p-5">
        <ActionQueueHeader
            :stale-count="staleCount"
            :refreshing="refreshing"
            @refresh="refresh"
        />

        <ActionBatchFilterChip
            v-if="filters.subtitle_audit_batch !== null"
            :batch-id="filters.subtitle_audit_batch"
            :status="filters.status"
        />

        <SubtitleAuditManualPicks
            v-if="showSubtitleAuditFollowUps && subtitleAuditBatch"
            :batch="subtitleAuditBatch"
        />

        <ActionStatusTabs
            :status="filters.status"
            :status-counts="statusCounts"
            :total="requests.meta.total"
            @select="setFilter"
        />

        <!-- Table + Detail -->
        <div class="grid gap-4 lg:grid-cols-[1.6fr_1fr] lg:items-start">
            <ActionRequestTable
                v-model:selected-id="selectedId"
                :rows="visibleRequests"
                :pending-ids="pendingOnPage"
                :bulk-ids="bulkIds"
                :bulk-selected-on-page="bulkSelectedOnPage"
                :bulk-busy="bulkBusy"
                @toggle="toggleBulk"
                @toggle-all="(value) => setAllBulk(pendingOnPage, value)"
            />

            <ActionRequestCards
                :rows="visibleRequests"
                :bulk-ids="bulkIds"
                :bulk-busy="bulkBusy"
                @open="openSheet"
                @toggle="toggleBulk"
            />

            <ActionRequestDetail
                :selected="selected"
                @approve="approve"
                @reject="reject"
                @retry="retry"
            />
        </div>

        <BulkActionBar
            v-if="isAdmin"
            :count="bulkCount"
            :busy="bulkBusy"
            @clear="clearBulk()"
        >
            <template #default="{ disabled }">
                <Button
                    size="sm"
                    class="h-7 text-xs"
                    :disabled="disabled"
                    data-bulk-approve
                    @click="bulkReview('approve')"
                >
                    Approve
                </Button>
                <Button
                    size="sm"
                    variant="destructive"
                    class="h-7 text-xs"
                    :disabled="disabled"
                    data-bulk-reject
                    @click="rejectDialogOpen = true"
                >
                    Reject…
                </Button>
            </template>
            <template #primary="{ disabled }">
                <Button
                    size="sm"
                    class="h-10 px-4 text-xs"
                    :disabled="disabled"
                    data-bulk-approve-phone
                    @click="bulkReview('approve')"
                >
                    Approve
                </Button>
            </template>
            <template #menu="{ disabled }">
                <DropdownMenuItem
                    :disabled="bulkBusy"
                    data-bulk-select-all-phone
                    @select="setAllBulk(pendingOnPage, true)"
                >
                    Select all on this page
                </DropdownMenuItem>
                <DropdownMenuItem
                    :disabled="disabled"
                    class="text-destructive focus:text-destructive"
                    data-bulk-menu-item="reject"
                    @select="rejectDialogOpen = true"
                >
                    Reject…
                </DropdownMenuItem>
            </template>
        </BulkActionBar>

        <BulkRejectDialog
            v-model:open="rejectDialogOpen"
            v-model:reason="rejectReason"
            :count="bulkCount"
            :busy="bulkBusy"
            @confirm="bulkReview('reject')"
        />

        <ActionRequestSheet
            v-model:open="sheetOpen"
            :selected="selected"
            @approve="approve"
            @reject="reject"
            @retry="retry"
        />

        <ActionQueuePagination
            v-if="requests.links.length > 3"
            :links="requests.links"
            @go="goToPage"
        />
    </div>
</template>
