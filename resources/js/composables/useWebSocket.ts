import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

interface ReverbConfig {
    key: string;
    host: string;
    port: number;
    scheme: 'http' | 'https';
}

let echoInstance: Echo<'reverb'> | null = null;

interface PusherConnection {
    state: string;
    bind: (event: string, callback: (payload: unknown) => void) => void;
}

interface ConnectorWithPusher {
    pusher?: { connection: PusherConnection };
}

const connectionChangeCallbacks = new Set<(connected: boolean) => void>();
const reconnectCallbacks = new Set<() => void>();
let connection: PusherConnection | null = null;
let wasConnected = false;
let hasConnected = false;

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

        watchConnection(echoInstance);
    }

    return echoInstance;
}

/**
 * The Reverb socket id, or null before Echo exists. Unlike `echo().socketId()`
 * it never creates Echo, so a caller that runs on every request (Inertia's
 * `X-Socket-Id` header) opens no socket on a page that has none.
 */
export function currentSocketId(): string | null {
    return echoInstance?.socketId() ?? null;
}

/**
 * One `state_change` binding on the pusher-js connection for the whole app,
 * fanned out to {@link onConnectionChange} and {@link onReconnect}. It is made
 * when Echo is, so subscribing to either never opens a socket on its own (a
 * guest page has no use for one).
 */
function watchConnection(echo: Echo<'reverb'>): void {
    const pusher = (echo.connector as unknown as ConnectorWithPusher).pusher;

    if (!pusher) {
        return;
    }

    connection = pusher.connection;
    wasConnected = connection.state === 'connected';
    hasConnected = wasConnected;

    connection.bind('state_change', (payload: unknown) => {
        const current = (payload as { current?: unknown } | undefined)?.current;

        if (typeof current !== 'string') {
            return;
        }

        const connected = current === 'connected';
        const reconnected = connected && !wasConnected && hasConnected;
        wasConnected = connected;
        hasConnected ||= connected;

        for (const callback of connectionChangeCallbacks) {
            callback(connected);
        }

        if (reconnected) {
            for (const callback of reconnectCallbacks) {
                callback();
            }
        }
    });
}

/**
 * Calls back with whether the Reverb connection is up on every pusher-js
 * state change, and once right away when Echo already exists. Repeats of the
 * same value are possible. Returns the unsubscribe.
 */
export function onConnectionChange(
    callback: (connected: boolean) => void,
): () => void {
    connectionChangeCallbacks.add(callback);

    if (connection) {
        callback(wasConnected);
    }

    return () => {
        connectionChangeCallbacks.delete(callback);
    };
}

/**
 * Calls back when the connection comes back after having dropped since an
 * earlier `connected`; never on the first connect. Returns the unsubscribe.
 */
export function onReconnect(callback: () => void): () => void {
    reconnectCallbacks.add(callback);

    return () => {
        reconnectCallbacks.delete(callback);
    };
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

export function acquirePrivateChannel(name: string): ChannelLease {
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
    onConnectionChange: typeof onConnectionChange;
    onReconnect: typeof onReconnect;
}

export function useWebSocket(): UseWebSocket {
    return {
        echo: getEcho,
        privateChannel: (channel: string) => getEcho().private(channel),
        leaveChannel: (channel: string) => getEcho().leave(channel),
        acquirePrivateChannel,
        onConnectionChange,
        onReconnect,
    };
}
