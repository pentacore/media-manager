import type { LiveTransport } from '@inertiajs/core';
import type { ChannelLease } from '@/composables/useWebSocket';
import {
    acquirePrivateChannel,
    currentSocketId,
    onConnectionChange,
} from '@/composables/useWebSocket';

/**
 * Inertia live props over our own channel leases rather than Inertia's
 * bundled `echo()` transport, which counts its own listeners and leaves a
 * channel on its last one: that would tear down a channel a hand-written
 * listener (the nav badges, the dashboard) still holds, and the reverse.
 * Here `useWebSocket` stays the single owner of every channel subscription.
 *
 * Inertia refreshes every live prop by itself when `onStatusChange` reports a
 * dropped connection back, so nothing here reloads.
 *
 * Never import this from ssr.ts: there are no sockets during SSR.
 */
export const liveTransport: LiveTransport = {
    subscribe(channel, event, handler) {
        if (channel.type !== 'private') {
            console.warn(
                `Live props only support private channels; "${channel.name}" is ${channel.type}.`,
            );

            return () => {};
        }

        // As Inertia's echo() transport formats it: a leading `.` makes Echo
        // use the name as broadcast (no `App\Events` namespace), and a
        // leading `\` marks a name that is already fully qualified.
        const name =
            event.startsWith('.') || event.startsWith('\\')
                ? event
                : `.${event}`;

        // This runs during a page swap, and a throw would take the swap with it.
        // The lease lives outside `listen`: `listen` can throw after the lease
        // already counts, and an unreleased lease would pin the channel forever.
        let lease: ChannelLease | null = null;

        try {
            lease = acquirePrivateChannel(channel.name);
            lease.listen(name, handler);

            // The lease stops only this handler while another consumer still
            // holds the channel, and leaves the channel on the last release.
            const held = lease;

            return () => held.release();
        } catch (error) {
            try {
                lease?.release();
            } catch (releaseError) {
                console.warn(
                    'Could not release the live channel lease.',
                    releaseError,
                );
            }

            console.warn(
                `Could not subscribe to the live channel "${channel.name}".`,
                error,
            );

            return () => {};
        }
    },
    // Inertia asks on every request, so this must not create Echo itself.
    socketId: currentSocketId,
    onStatusChange: (callback) => onConnectionChange(callback),
};
