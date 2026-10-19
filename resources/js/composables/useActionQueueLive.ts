import { router } from '@inertiajs/vue3';
import type { ComputedRef, Ref } from 'vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import { mergeLiveResult } from '@/components/actions/failure';
import {
    compareLiveStatus,
    liveChangeKey,
    pushedRowIsCurrent,
    reconcileWithLive,
} from '@/components/actions/liveStatus';
import type { LiveStatusChange } from '@/components/actions/liveStatus';
import type {
    ActionQueueRequests,
    ActionRequestRow,
} from '@/components/actions/types';
import { useCan } from '@/composables/useCan';
import { useRealtimeList } from '@/composables/useRealtimeList';
import { useWebSocket } from '@/composables/useWebSocket';
import type { ChannelLease } from '@/composables/useWebSocket';

const ACTIONS_CHANNEL = 'members.actions';

/** The page props the live queue reads; pass the page's `props` object. */
export type ActionQueueLiveProps = {
    requests: ActionQueueRequests;
    filters: { status: string };
};

export type UseActionQueueLiveReturn = {
    /** The rows to list: the live list on the unfiltered first page, else the prop. */
    visibleRequests: ComputedRef<ActionRequestRow[]>;
    /** Requests created live while the list is paused (a filter or a later page). */
    staleCount: Ref<number>;
    refreshing: Ref<boolean>;
    refresh: () => void;
    /** The user's own review settles these rows: their next server row wins. */
    forgetLiveStatuses: (ids: number[]) => void;
    /** Binds the channels and visit listeners; call it from the page's `onMounted`. */
    subscribe: () => void;
    /** Releases them; the composable also calls it on unmount. */
    unsubscribe: () => void;
};

/**
 * The Action Queue's live list. On the unfiltered first page, requests created
 * live are merged into the listed rows (a late Created broadcast never rolls a
 * newer row back); elsewhere they are counted for the "N new" notice. Status
 * changes apply to listed rows in version order, are remembered so a reload or
 * redirect read before them cannot roll a row back, and reload the tab counts
 * (and, for an admin's failure, the rows) on a 500 ms debounce. An admin also
 * reloads a listed row whose replacement attempt changed. The page calls
 * `subscribe()` from its `onMounted` to take the channel leases and Inertia
 * listeners; they are released on unmount, so each visit binds them once.
 */
export function useActionQueueLive(
    props: ActionQueueLiveProps,
): UseActionQueueLiveReturn {
    const { isAdmin } = useCan();

    const hasFilter = computed(() => props.filters.status !== '');
    const onFirstPage = computed(() => props.requests.meta.current_page === 1);
    const merge = computed(() => !hasFilter.value && onFirstPage.value);

    const {
        items: liveRequests,
        staleCount,
        pause,
        resume,
        reseed,
        subscribe: subscribeCreated,
    } = useRealtimeList<ActionRequestRow>({
        channel: ACTIONS_CHANNEL,
        event: 'ActionRequestCreated',
        keyField: 'id',
        initial: props.requests.data,
        cap: props.requests.meta.per_page,
        // A late Created broadcast must not roll back a row a newer status
        // change already moved on.
        replaces: pushedRowIsCurrent,
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

    // The newest live status seen for each listed row. A reload or a redirect
    // can be read before a fast change was written and land after its
    // broadcast; reseeding from it must not roll the row back.
    const liveStatuses = new Map<number, LiveStatusChange>();

    // When each remembered status arrived, and when each visit that brings
    // `requests` started, on one counter. A request sent after a status arrived
    // read the database after that status was broadcast (a broadcast is built
    // from a committed row), so its row is the truth even when its version is
    // lower: the versions went backwards (a restored database, a bogus payload),
    // and keeping the remembered status would pin the row until a remount.
    let liveClock = 0;
    const liveStatusArrivedAt = new Map<number, number>();
    const requestsVisitStartedAt = new Map<string, number>();

    // The live change each kept failure already fetched its message for: a
    // reseed that keeps a live failure reloads `requests` once more for it, and
    // only once, so a row the server keeps reading stale cannot loop.
    const failureFollowUps = new Map<number, string>();

    /** The user's own review settles these rows: their next server row wins. */
    function forgetLiveStatuses(ids: number[]): void {
        ids.forEach((id) => {
            liveStatuses.delete(id);
            liveStatusArrivedAt.delete(id);
            failureFollowUps.delete(id);
        });
    }

    function reconcileReloadedRow(row: ActionRequestRow): ActionRequestRow {
        const live = liveStatuses.get(row.id);

        if (live === undefined) {
            return row;
        }

        if (compareLiveStatus(live, row) <= 0) {
            forgetLiveStatuses([row.id]);

            return row;
        }

        return reconcileWithLive(row, live);
    }

    // Filter changes navigate with preserveState (no remount) and Refresh
    // replaces the prop in place, so the realtime list must be reseeded from
    // every fresh server payload — otherwise it keeps rendering the seed from
    // whatever filter/page this component originally mounted with.
    watch(
        () => props.requests.data,
        (rows) => reseed(rows.map(reconcileReloadedRow)),
    );

    /**
     * A visit that brought `requests` succeeded. Its rows win over every status
     * remembered before it started; a status that arrived after it started is
     * still ordered against its rows (the reload may have read the row before
     * the change was written). Then, for an admin, a kept live failure asks for
     * one more `requests` reload: the reseeded row only has the broadcast's
     * member-safe result, and the message and outcome flag come with a later read.
     */
    function settleRequestsVisit(
        rows: ActionRequestRow[],
        startedAt: number,
    ): void {
        const superseded = rows
            .map((row) => row.id)
            .filter(
                (id) => (liveStatusArrivedAt.get(id) ?? Infinity) < startedAt,
            );

        if (superseded.length > 0) {
            forgetLiveStatuses(superseded);
            reseed(rows.map(reconcileReloadedRow));
        }

        if (!isAdmin.value) {
            return;
        }

        rows.forEach((row) => {
            const live = liveStatuses.get(row.id);

            if (
                live === undefined ||
                live.status !== 'failed' ||
                compareLiveStatus(live, row) <= 0
            ) {
                return;
            }

            const key = liveChangeKey(live);

            if (failureFollowUps.get(row.id) !== key) {
                failureFollowUps.set(row.id, key);
                scheduleRequestsReload();
            }
        });
    }

    const visibleRequests = computed<ActionRequestRow[]>(() =>
        merge.value ? liveRequests.value : props.requests.data,
    );

    const { acquirePrivateChannel } = useWebSocket();
    let statusLease: ChannelLease | null = null;

    function applyStatusChange(payload: LiveStatusChange): void {
        const target = liveRequests.value.find((row) => row.id === payload.id);

        if (!target) {
            return;
        }

        // Delivered out of order: an event older than one already applied — or,
        // when both it and the row on screen carry a status version, older than
        // the row — changes nothing.
        const remembered = liveStatuses.get(payload.id);
        const older =
            remembered !== undefined
                ? compareLiveStatus(payload, remembered) < 0
                : typeof payload.status_version === 'number' &&
                  typeof target.status_version === 'number' &&
                  compareLiveStatus(payload, target) < 0;

        if (older) {
            return;
        }

        liveStatuses.set(payload.id, payload);
        liveStatusArrivedAt.set(payload.id, ++liveClock);

        const statusChanged = target.status !== payload.status;

        target.result = mergeLiveResult(
            target.result,
            payload.result,
            !statusChanged,
        );
        target.status = payload.status;
        // Keep the row's own order fields in step with what it shows, as
        // reconcileWithLive() does.
        target.updated_at = payload.updated_at;

        if (typeof payload.status_version === 'number') {
            target.status_version = payload.status_version;
        }

        // The broadcast carries only the member-safe result. An admin's failure
        // message and outcome flag come with the server-rendered `requests`, so
        // every live failure reloads them: a same-status event may follow missed
        // ones (a retry and a new failure) and carry a different outcome.
        if (payload.status === 'failed' && isAdmin.value) {
            scheduleRequestsReload();
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

    // Attempt state lives on the server-rendered `requests` prop, so a changed
    // attempt needs a fresh page payload rather than an in-memory patch.
    let attemptLease: ChannelLease | null = null;
    let requestsReloadTimer: ReturnType<typeof setTimeout> | null = null;

    function scheduleRequestsReload(): void {
        if (requestsReloadTimer !== null) {
            return;
        }

        requestsReloadTimer = setTimeout(() => {
            requestsReloadTimer = null;
            router.reload({ only: ['requests'] });
        }, 500);
    }

    let stopVisitStartListener: VoidFunction | null = null;
    let stopVisitSuccessListener: VoidFunction | null = null;
    let stopVisitFinishListener: VoidFunction | null = null;

    /**
     * Binds the live list, the status and attempt channels and the Inertia
     * visit listeners. The page calls it from `onMounted`, once per mount.
     */
    function subscribe(): void {
        subscribeCreated();

        stopVisitStartListener = router.on('start', (event) => {
            const visit = event.detail.visit;

            if (
                !visit.prefetch &&
                ((visit.only.length === 0 && visit.except.length === 0) ||
                    visit.only.includes('requests'))
            ) {
                requestsVisitStartedAt.set(visit.id, ++liveClock);
            }
        });

        stopVisitSuccessListener = router.on('success', (event) => {
            const visitId = event.detail.visitId;
            const startedAt =
                visitId === undefined
                    ? undefined
                    : requestsVisitStartedAt.get(visitId);

            if (visitId === undefined || startedAt === undefined) {
                return;
            }

            requestsVisitStartedAt.delete(visitId);

            const requests = event.detail.page.props.requests as
                ActionQueueRequests | undefined;

            if (requests !== undefined) {
                settleRequestsVisit(requests.data, startedAt);
            }
        });

        // Visits that never reach 'success' (cancelled, errored) would
        // otherwise leak their start.
        stopVisitFinishListener = router.on('finish', (event) => {
            requestsVisitStartedAt.delete(event.detail.visit.id);
        });

        statusLease = acquirePrivateChannel(ACTIONS_CHANNEL)
            .listen(
                '.ActionRequestStatusChanged',
                (event: LiveStatusChange) => {
                    applyStatusChange(event);
                    scheduleStatusCountsReload();
                },
            )
            .listen('.ActionRequestCreated', () =>
                scheduleStatusCountsReload(),
            );

        // Admin-only channel; members never see attempt detail and would 403 on
        // channel auth.
        if (isAdmin.value) {
            attemptLease = acquirePrivateChannel(
                'admin.media-replacement',
            ).listen(
                '.MediaReplacementAttemptChanged',
                (event: { action_request_id: number }) => {
                    if (
                        visibleRequests.value.some(
                            (row) => row.id === event.action_request_id,
                        )
                    ) {
                        scheduleRequestsReload();
                    }
                },
            );
        }
    }

    /** Releases everything subscribe() bound; also runs on unmount. */
    function unsubscribe(): void {
        statusLease?.release();
        statusLease = null;

        stopVisitStartListener?.();
        stopVisitSuccessListener?.();
        stopVisitFinishListener?.();

        if (statusCountsReloadTimer !== null) {
            clearTimeout(statusCountsReloadTimer);
            statusCountsReloadTimer = null;
        }

        attemptLease?.release();
        attemptLease = null;

        if (requestsReloadTimer !== null) {
            clearTimeout(requestsReloadTimer);
            requestsReloadTimer = null;
        }
    }

    onUnmounted(unsubscribe);

    const refreshing = ref(false);

    function refresh(): void {
        if (refreshing.value) {
            return;
        }

        refreshing.value = true;
        // Refresh asks for the server's rows as they are now: forget every
        // remembered live status, so none of them can outrank the answer.
        forgetLiveStatuses([...liveStatuses.keys()]);
        // The prop watcher reseeds the realtime list from the fresh payload;
        // pause/resume stays governed by the merge watcher, so a refresh in a
        // filtered view no longer un-pauses the invisible live list.
        router.reload({
            only: ['requests'],
            onFinish: () => {
                refreshing.value = false;
            },
        });
    }

    return {
        visibleRequests,
        staleCount,
        refreshing,
        refresh,
        forgetLiveStatuses,
        subscribe,
        unsubscribe,
    };
}
