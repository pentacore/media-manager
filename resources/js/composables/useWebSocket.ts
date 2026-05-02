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

export interface UseWebSocket {
    echo: () => Echo<'reverb'>;
    privateChannel: (channel: string) => ReturnType<Echo<'reverb'>['private']>;
    leaveChannel: (channel: string) => void;
}

export function useWebSocket(): UseWebSocket {
    return {
        echo: getEcho,
        privateChannel: (channel: string) => getEcho().private(channel),
        leaveChannel: (channel: string) => getEcho().leave(channel),
    };
}
