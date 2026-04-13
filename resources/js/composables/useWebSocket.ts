import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echoInstance: Echo<'reverb'> | null = null;

function getEcho(): Echo<'reverb'> {
    if (!echoInstance) {
        // Pusher must be available globally for Echo's Reverb driver
        window.Pusher = Pusher;

        echoInstance = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: Number(import.meta.env.VITE_REVERB_PORT),
            wssPort: Number(import.meta.env.VITE_REVERB_PORT),
            forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
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
