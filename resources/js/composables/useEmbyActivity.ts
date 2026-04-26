import type { Ref } from 'vue';
import { useRealtimeList } from '@/composables/useRealtimeList';

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
    const { items, subscribe, unsubscribe } = useRealtimeList<NowPlayingItem>({
        channel: 'emby.activity',
        event: 'EmbyPlaybackUpdated',
        keyField: 'id',
        cap: 10,
    });

    return { nowPlaying: items, subscribe, unsubscribe };
}
