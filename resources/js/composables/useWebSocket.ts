import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

interface ReverbConfig {
    key: string;
    host: string;
    port: number;
    scheme: 'http' | 'https';
}

let echoInstance: Echo<'reverb'> | null = null;

function readReverbConfig(): ReverbConfig {
    const meta = document.querySelector<HTMLMetaElement>(
        'meta[name="reverb-config"]',
    );

    if (!meta?.content) {
        throw new Error('Missing <meta name="reverb-config"> in page head');
    }

    return JSON.parse(meta.content) as ReverbConfig;
}

function getEcho(): Echo<'reverb'> {
    if (!echoInstance) {
        // Pusher must be available globally for Echo's Reverb driver
        window.Pusher = Pusher;

        const config = readReverbConfig();

        echoInstance = new Echo({
            broadcaster: 'reverb',
            key: config.key,
            wsHost: config.host,
            wsPort: config.port,
            wssPort: config.port,
            forceTLS: config.scheme === 'https',
            enabledTransports: ['ws', 'wss'],
        });
    }

    return echoInstance;
}

export interface ChannelLease {
    listen: <T>(event: string, callback: (payload: T) => void) => ChannelLease;
    release: () => void;
}

/**
 * How many live leases exist per channel name. Echo.leave() tears down the
 * underlying subscription for EVERY consumer — a page unmounting used to
 * silently kill the persistent sidebar's listeners on shared channels like
 * `members.actions`. The channel is only left once the last lease releases.
 */
const channelLeases = new Map<string, number>();

function acquirePrivateChannel(name: string): ChannelLease {
    const channel = getEcho().private(name);
    channelLeases.set(name, (channelLeases.get(name) ?? 0) + 1);

    const listeners: Array<{
        event: string;
        callback: (payload: never) => void;
    }> = [];
    let released = false;

    const lease: ChannelLease = {
        listen<T>(event: string, callback: (payload: T) => void): ChannelLease {
            channel.listen(event, callback);
            listeners.push({ event, callback });

            return lease;
        },
        release(): void {
            if (released) {
                return;
            }

            released = true;

            const remaining = (channelLeases.get(name) ?? 1) - 1;

            if (remaining <= 0) {
                // Last consumer: tear the whole channel down.
                channelLeases.delete(name);
                getEcho().leave(name);

                return;
            }

            channelLeases.set(name, remaining);

            // Others still hold the channel — remove only OUR callbacks so
            // they neither leak nor fire twice after this page remounts.
            for (const { event, callback } of listeners) {
                channel.stopListening(event, callback);
            }
        },
    };

    return lease;
}

export interface UseWebSocket {
    echo: () => Echo<'reverb'>;
    /**
     * Prefer {@link acquirePrivateChannel}: raw private()/leave() pairs
     * cannot coexist with other consumers of the same channel.
     */
    privateChannel: (channel: string) => ReturnType<Echo<'reverb'>['private']>;
    leaveChannel: (channel: string) => void;
    acquirePrivateChannel: (channel: string) => ChannelLease;
}

export function useWebSocket(): UseWebSocket {
    return {
        echo: getEcho,
        privateChannel: (channel: string) => getEcho().private(channel),
        leaveChannel: (channel: string) => getEcho().leave(channel),
        acquirePrivateChannel,
    };
}
