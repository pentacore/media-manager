import { router, usePage } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { useCan } from '@/composables/useCan';
import type { ChannelLease } from '@/composables/useWebSocket';
import { useWebSocket } from '@/composables/useWebSocket';

export type NavCounts = {
    pendingActions: Ref<number>;
    activeSessions: Ref<number>;
    libraryIntervention: Ref<number>;
    sabnzbdQueued: Ref<number>;
    sabnzbdCompleted: Ref<number>;
    replacementAttention: Ref<number>;
    wantedMissing: Ref<number>;
};

type PlaybackPayload = {
    id: number;
    action: string;
};

type ActionRequestCountPayload = {
    id: number;
    /** Every pending request when the broadcast was sent. */
    pending_count?: number;
};

type ReplacementAttemptPayload = {
    attention_unacknowledged: number;
};

/** The counters a live event moves; applyNav() guards each one. */
type LiveCounter =
    | 'pendingActions'
    | 'activeSessions'
    | 'libraryIntervention'
    | 'sabnzbd'
    | 'replacementAttention';

const SESSION_EXPIRY_MS = 10 * 60 * 1000;

/** A URL's path and query, to match a visit with the page it rendered. */
function pageKey(url: string | URL): string {
    const parsed = new URL(url.toString(), window.location.origin);

    return `${parsed.pathname}${parsed.search}`;
}

/**
 * Live counters for the sidebar badges. Seeded from the `nav` shared prop and
 * kept current by private channels. `nav` is a lazy shared prop: full page
 * visits (and prefetched ones) send a fresh object, partial reloads omit it
 * unless they name it, and Inertia keeps the previous object.
 */
export function useNavCounts(): NavCounts {
    const page = usePage();
    const initialNav = page.props.nav;

    const pendingActions = ref(initialNav?.pendingActions ?? 0);
    const activeSessions = ref(initialNav?.activeSessions ?? 0);
    const libraryIntervention = ref(initialNav?.libraryIntervention ?? 0);
    const sabnzbdQueued = ref(initialNav?.sabnzbdDownloads?.queued ?? 0);
    const sabnzbdCompleted = ref(initialNav?.sabnzbdDownloads?.completed ?? 0);
    const replacementAttention = ref(initialNav?.replacementAttention ?? 0);
    const wantedMissing = ref(initialNav?.wantedMissing ?? 0);
    const { can } = useCan();

    const recentSessionIds = new Set<number>();
    const sessionTimestamps = new Map<number, number>();
    // When a live event last moved each counter (client clock).
    const liveChangedAt = new Map<LiveCounter, number>();
    // When the hover prefetch cached for a page was fetched (client clock).
    const prefetchFetchedAt = new Map<string, number>();

    const { acquirePrivateChannel } = useWebSocket();
    const channelLeases: ChannelLease[] = [];

    let activitySessionTimer: ReturnType<typeof setInterval> | null = null;

    function markLive(counter: LiveCounter): void {
        liveChangedAt.set(counter, Date.now());
    }

    function pruneStaleSessions(): void {
        const cutoff = Date.now() - SESSION_EXPIRY_MS;
        let expired = 0;

        for (const [id, ts] of sessionTimestamps) {
            if (ts < cutoff) {
                sessionTimestamps.delete(id);
                recentSessionIds.delete(id);
                expired += 1;
            }
        }

        // Subtract only what expired locally. The counter is seeded from the
        // server snapshot, which includes sessions this tab never saw an event
        // for — overwriting with the locally-observed map size zeroed the badge
        // within a minute of every page load.
        if (expired > 0) {
            activeSessions.value = Math.max(0, activeSessions.value - expired);
        }
    }

    /**
     * Re-sync the counters from a `nav` snapshot: server truth as of
     * `snapshotAt` (client clock; Infinity for the response to a request
     * just sent). A counter a live event moved after `snapshotAt` keeps its
     * live value, because the snapshot predates that event. The session sets
     * only track deltas since the previous snapshot, so they are cleared
     * together with their counter.
     */
    function applyNav(
        nav: typeof page.props.nav | undefined,
        snapshotAt = Number.POSITIVE_INFINITY,
    ): void {
        if (!nav) {
            return;
        }

        const isCurrent = (counter: LiveCounter): boolean =>
            (liveChangedAt.get(counter) ?? Number.NEGATIVE_INFINITY) <=
            snapshotAt;

        if (isCurrent('pendingActions')) {
            pendingActions.value = nav.pendingActions ?? 0;
        }

        if (isCurrent('activeSessions')) {
            activeSessions.value = nav.activeSessions ?? 0;
            recentSessionIds.clear();
            sessionTimestamps.clear();
        }

        if (isCurrent('libraryIntervention')) {
            libraryIntervention.value = nav.libraryIntervention ?? 0;
        }

        if (isCurrent('sabnzbd')) {
            sabnzbdQueued.value = nav.sabnzbdDownloads?.queued ?? 0;
            sabnzbdCompleted.value = nav.sabnzbdDownloads?.completed ?? 0;
        }

        if (isCurrent('replacementAttention')) {
            replacementAttention.value = nav.replacementAttention ?? 0;
        }

        wantedMissing.value = nav.wantedMissing ?? 0;
    }

    /**
     * When the `nav` the page now shows was produced: the fetch time of the
     * hover prefetch a click may have reused for this URL, else "now". A
     * response to a request that was really sent is re-applied as "now" on
     * 'success', so an older date here only delays that.
     */
    function snapshotTimeFor(url: string): number {
        return prefetchFetchedAt.get(pageKey(url)) ?? Number.POSITIVE_INFINITY;
    }

    // Re-sync when a fresh `nav` snapshot arrives. Watch the reference, not
    // every page update: a partial reload keeps the previous `nav` object,
    // and re-applying that older snapshot would undo the live deltas the
    // channels below added since. A click that reuses a hover prefetch also
    // lands here, with the hover-time `nav`: snapshotTimeFor() dates it.
    watch(
        () => page.props.nav,
        (nav) => applyNav(nav, snapshotTimeFor(page.url)),
    );

    // The reference watch above misses one case: a full visit back to the
    // same component whose `nav` payload is byte-identical to the previous
    // one. Inertia's preserveEqualProps then reuses the old object
    // (@inertiajs/core dist/index.js:2797-2806), so the watch never fires,
    // and locally-drifted counts (a websocket delta applied after the old
    // snapshot, now rolled back by the full visit) are left stale. Track
    // every visit's `only`/`except` at 'start' and force a re-sync from
    // `page.props.nav` on 'success' for the non-prefetch requests that
    // bring `nav` (full visits, and reloads that name it) — regardless of
    // whether the reference changed. The watch may first have dated that
    // `nav` by an older prefetch of the same URL; this re-apply makes it
    // current. A click that reuses a prefetch sends no request, so it fires
    // no 'start' and keeps the prefetch's date.
    const pendingFullVisitIds = new Set<string>();
    let stopStartListener: VoidFunction | null = null;
    let stopSuccessListener: VoidFunction | null = null;
    let stopFinishListener: VoidFunction | null = null;
    let stopPrefetchedListener: VoidFunction | null = null;

    onMounted(() => {
        stopStartListener = router.on('start', (event) => {
            const visit = event.detail.visit;

            if (visit.prefetch) {
                return;
            }

            // A request that is really sent never forgets its URL's prefetch
            // date: Inertia keeps the cached prefetch reusable for its whole
            // `cacheFor` window (a partial reload carries other params, so it
            // never replaces it), and a later click can still bring that
            // hover-time `nav`. The `nav` this request brings back is
            // re-applied as current on 'success' instead.
            if (
                (visit.only.length === 0 && visit.except.length === 0) ||
                visit.only.includes('nav')
            ) {
                pendingFullVisitIds.add(visit.id);
            }
        });

        // A click that reuses a prefetch fires no 'start'; remember when
        // each prefetch was fetched so its `nav` can be dated.
        stopPrefetchedListener = router.on('prefetched', (event) => {
            prefetchFetchedAt.set(
                pageKey(event.detail.visit.url),
                event.detail.fetchedAt,
            );
        });

        stopSuccessListener = router.on('success', (event) => {
            const visitId = event.detail.visitId;

            if (visitId !== undefined && pendingFullVisitIds.delete(visitId)) {
                applyNav(page.props.nav);
            }
        });

        // Visits that never reach 'success' (cancelled, errored) would
        // otherwise leak their id.
        stopFinishListener = router.on('finish', (event) => {
            pendingFullVisitIds.delete(event.detail.visit.id);
        });

        channelLeases.push(
            acquirePrivateChannel('emby.activity').listen(
                '.EmbyPlaybackUpdated',
                (event: PlaybackPayload) => {
                    if (event.action === 'played') {
                        if (!recentSessionIds.has(event.id)) {
                            recentSessionIds.add(event.id);
                            activeSessions.value += 1;
                            markLive('activeSessions');
                        }

                        sessionTimestamps.set(event.id, Date.now());
                    } else {
                        if (recentSessionIds.has(event.id)) {
                            recentSessionIds.delete(event.id);
                            sessionTimestamps.delete(event.id);
                            activeSessions.value = Math.max(
                                0,
                                activeSessions.value - 1,
                            );
                            markLive('activeSessions');
                        }
                    }
                },
            ),
        );

        if (can('manage-library')) {
            // Both events carry the server's pending count, taken as is: a
            // per-id delta could not lower the badge for a request that was
            // pending before this tab opened.
            const applyPendingCount = (
                event: ActionRequestCountPayload,
            ): void => {
                if (typeof event.pending_count !== 'number') {
                    return;
                }

                pendingActions.value = event.pending_count;
                markLive('pendingActions');
            };

            channelLeases.push(
                acquirePrivateChannel('members.actions')
                    .listen('.ActionRequestCreated', applyPendingCount)
                    .listen('.ActionRequestStatusChanged', applyPendingCount),
            );
        }

        channelLeases.push(
            acquirePrivateChannel('dashboard')
                .listen(
                    '.LibraryInterventionChanged',
                    (event: { count: number }) => {
                        libraryIntervention.value = event.count;
                        markLive('libraryIntervention');
                    },
                )
                .listen(
                    '.SabnzbdDownloadCountsChanged',
                    (event: { queued: number; completed: number }) => {
                        sabnzbdQueued.value = event.queued;
                        sabnzbdCompleted.value = event.completed;
                        markLive('sabnzbd');
                    },
                ),
        );

        // Admin-only channel: subscribing as a member would 403 on auth and
        // spam the console, and members never see this badge anyway.
        if (can('admin')) {
            channelLeases.push(
                acquirePrivateChannel('admin.media-replacement').listen(
                    '.MediaReplacementAttemptChanged',
                    (event: ReplacementAttemptPayload) => {
                        replacementAttention.value =
                            event.attention_unacknowledged;
                        markLive('replacementAttention');
                    },
                ),
            );
        }

        activitySessionTimer = setInterval(pruneStaleSessions, 60_000);
    });

    onUnmounted(() => {
        channelLeases.splice(0).forEach((lease) => lease.release());

        if (activitySessionTimer) {
            clearInterval(activitySessionTimer);
            activitySessionTimer = null;
        }

        stopStartListener?.();
        stopSuccessListener?.();
        stopFinishListener?.();
        stopPrefetchedListener?.();
    });

    return {
        pendingActions,
        activeSessions,
        libraryIntervention,
        sabnzbdQueued,
        sabnzbdCompleted,
        replacementAttention,
        wantedMissing,
    };
}
