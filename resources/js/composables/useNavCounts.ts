import { usePage } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { onMounted, onUnmounted, ref, watchEffect } from 'vue';
import type { ChannelLease } from '@/composables/useWebSocket';
import { useWebSocket } from '@/composables/useWebSocket';

export type NavCounts = {
    pendingActions: Ref<number>;
    activeSessions: Ref<number>;
    libraryIntervention: Ref<number>;
    sabnzbdQueued: Ref<number>;
    sabnzbdCompleted: Ref<number>;
    replacementAttention: Ref<number>;
};

type NavCountsPayload = {
    pendingActions?: number;
    activeSessions?: number;
    libraryIntervention?: number;
    sabnzbdDownloads?: { queued: number; completed: number };
    replacementAttention?: number;
};

type PlaybackPayload = {
    id: number;
    action: string;
};

type ActionRequestCreatedPayload = {
    id: number;
    requires_approval: boolean;
    status: string;
};

type ActionRequestStatusPayload = {
    id: number;
    status: string;
};

type ReplacementAttemptPayload = {
    attention_unacknowledged: number;
};

const TERMINAL_STATUSES = new Set(['completed', 'failed', 'rejected']);
const SESSION_EXPIRY_MS = 10 * 60 * 1000;

/**
 * Live counters for the sidebar badges. Seeded from the `nav` shared prop and
 * kept current by three private channels.
 */
export function useNavCounts(): NavCounts {
    const page = usePage();
    const initialNav = (page.props as unknown as { nav?: NavCountsPayload })
        .nav;

    const pendingActions = ref(initialNav?.pendingActions ?? 0);
    const activeSessions = ref(initialNav?.activeSessions ?? 0);
    const libraryIntervention = ref(initialNav?.libraryIntervention ?? 0);
    const sabnzbdQueued = ref(initialNav?.sabnzbdDownloads?.queued ?? 0);
    const sabnzbdCompleted = ref(initialNav?.sabnzbdDownloads?.completed ?? 0);
    const replacementAttention = ref(initialNav?.replacementAttention ?? 0);
    const role = page.props.auth.user?.role;
    const isAdmin = (typeof role === 'string' ? role : role?.value) === 'admin';

    const recentSessionIds = new Set<number>();
    const sessionTimestamps = new Map<number, number>();
    // Track which action ids we've already counted as pending so an
    // out-of-order Created/StatusChanged sequence doesn't double-count.
    const pendingIds = new Set<number>();

    const { acquirePrivateChannel } = useWebSocket();
    const channelLeases: ChannelLease[] = [];

    let activitySessionTimer: ReturnType<typeof setInterval> | null = null;

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

    // Re-sync all counters whenever a fresh `nav` snapshot arrives (every
    // navigation / partial reload shares it). The local ID sets only track
    // deltas observed since the previous snapshot, so they are cleared here —
    // keeping them would double-count events already baked into the server
    // numbers.
    watchEffect(() => {
        const nav = (page.props as unknown as { nav?: NavCountsPayload }).nav;

        if (nav) {
            pendingActions.value = nav.pendingActions ?? 0;
            activeSessions.value = nav.activeSessions ?? 0;
            libraryIntervention.value = nav.libraryIntervention ?? 0;
            sabnzbdQueued.value = nav.sabnzbdDownloads?.queued ?? 0;
            sabnzbdCompleted.value = nav.sabnzbdDownloads?.completed ?? 0;
            replacementAttention.value = nav.replacementAttention ?? 0;
            recentSessionIds.clear();
            sessionTimestamps.clear();
            pendingIds.clear();
        }
    });

    onMounted(() => {
        channelLeases.push(
            acquirePrivateChannel('emby.activity').listen(
                '.EmbyPlaybackUpdated',
                (event: PlaybackPayload) => {
                    if (event.action === 'played') {
                        if (!recentSessionIds.has(event.id)) {
                            recentSessionIds.add(event.id);
                            activeSessions.value += 1;
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
                        }
                    }
                },
            ),
        );

        channelLeases.push(
            acquirePrivateChannel('members.actions')
                .listen(
                    '.ActionRequestCreated',
                    (event: ActionRequestCreatedPayload) => {
                        if (
                            event.status === 'pending' &&
                            !pendingIds.has(event.id)
                        ) {
                            pendingIds.add(event.id);
                            pendingActions.value += 1;
                        }
                    },
                )
                .listen(
                    '.ActionRequestStatusChanged',
                    (event: ActionRequestStatusPayload) => {
                        if (
                            TERMINAL_STATUSES.has(event.status) ||
                            event.status === 'approved' ||
                            event.status === 'executing'
                        ) {
                            if (pendingIds.has(event.id)) {
                                pendingIds.delete(event.id);
                                pendingActions.value = Math.max(
                                    0,
                                    pendingActions.value - 1,
                                );
                            }
                        }
                    },
                ),
        );

        channelLeases.push(
            acquirePrivateChannel('dashboard')
                .listen(
                    '.LibraryInterventionChanged',
                    (event: { count: number }) => {
                        libraryIntervention.value = event.count;
                    },
                )
                .listen(
                    '.SabnzbdDownloadCountsChanged',
                    (event: { queued: number; completed: number }) => {
                        sabnzbdQueued.value = event.queued;
                        sabnzbdCompleted.value = event.completed;
                    },
                ),
        );

        // Admin-only channel: subscribing as a member would 403 on auth and
        // spam the console, and members never see this badge anyway.
        if (isAdmin) {
            channelLeases.push(
                acquirePrivateChannel('admin.media-replacement').listen(
                    '.MediaReplacementAttemptChanged',
                    (event: ReplacementAttemptPayload) => {
                        replacementAttention.value =
                            event.attention_unacknowledged;
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
    });

    return {
        pendingActions,
        activeSessions,
        libraryIntervention,
        sabnzbdQueued,
        sabnzbdCompleted,
        replacementAttention,
    };
}
