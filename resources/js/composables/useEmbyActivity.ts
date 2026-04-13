import { ref, onUnmounted, type Ref } from 'vue';
import { useWebSocket } from '@/composables/useWebSocket';

export interface NowPlayingItem {
    id: number;
    media_type: string;
    media_title: string;
    series_title: string | null;
    action: string;
    emby_username: string;
    updated_at: string;
}

export interface UseEmbyActivity {
    nowPlaying: Ref<NowPlayingItem[]>;
    subscribe: () => void;
    unsubscribe: () => void;
}

export function useEmbyActivity(): UseEmbyActivity {
    const { privateChannel, leaveChannel } = useWebSocket();
    const nowPlaying = ref<NowPlayingItem[]>([]);

    function subscribe(): void {
        privateChannel('emby.activity').listen('.EmbyPlaybackUpdated', (event: NowPlayingItem) => {
            const index = nowPlaying.value.findIndex((item) => item.id === event.id);
            if (index >= 0) {
                nowPlaying.value[index] = event;
            } else {
                nowPlaying.value.unshift(event);
                if (nowPlaying.value.length > 10) {
                    nowPlaying.value.pop();
                }
            }
        });
    }

    function unsubscribe(): void {
        leaveChannel('emby.activity');
    }

    onUnmounted(unsubscribe);

    return { nowPlaying, subscribe, unsubscribe };
}
