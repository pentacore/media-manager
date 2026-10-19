<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import ActionRequestController from '@/actions/App/Http/Controllers/Actions/ActionRequestController';
import {
    ActionQueueHeader,
    ActionQueuePagination,
    ActionRequestDetail,
    ActionRequestTable,
    ActionStatusTabs,
    BulkRejectDialog,
    isUncertainOutcome,
    retryConfirmation,
} from '@/components/actions';
import type {
    ActionQueueRequests,
    ActionRequestRow,
} from '@/components/actions';
import { BulkActionBar } from '@/components/bulk';
import { Button } from '@/components/ui/button';
import { useActionQueueLive } from '@/composables/useActionQueueLive';
import { useBulkSelection } from '@/composables/useBulkSelection';
import { useCan } from '@/composables/useCan';
import { useConfirm } from '@/composables/useConfirm';
import { focusAfterBulk, submitBulk } from '@/lib/bulk';
import { dashboard } from '@/routes';

const props = defineProps<{
    requests: ActionQueueRequests;
    statusCounts: Record<string, number>;
    filters: { status: string };
    preselect: number | null;
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
const { confirm } = useConfirm();

const {
    visibleRequests,
    staleCount,
    refreshing,
    refresh,
    forgetLiveStatuses,
    subscribe: subscribeLive,
} = useActionQueueLive(props);

const selectedId = ref<number | null>(
    props.preselect !== null &&
        visibleRequests.value.some((row) => row.id === props.preselect)
        ? props.preselect
        : (visibleRequests.value[0]?.id ?? null),
);

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

// Takes the live channel leases and visit listeners for this visit.
onMounted(subscribeLive);

function setFilter(id: string): void {
    router.get(
        ActionRequestController.index.url(),
        id === 'all' ? {} : { status: id },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function approve(id: number): void {
    forgetLiveStatuses([id]);
    router.post(
        ActionRequestController.approve.url(id),
        {},
        { preserveScroll: true },
    );
}

function reject(id: number): void {
    forgetLiveStatuses([id]);
    router.post(
        ActionRequestController.reject.url(id),
        {},
        { preserveScroll: true },
    );
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
        { preserveScroll: true },
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
        </BulkActionBar>

        <BulkRejectDialog
            v-model:open="rejectDialogOpen"
            v-model:reason="rejectReason"
            :count="bulkCount"
            :busy="bulkBusy"
            @confirm="bulkReview('reject')"
        />

        <ActionQueuePagination
            v-if="requests.links.length > 3"
            :links="requests.links"
            @go="goToPage"
        />
    </div>
</template>
