import type Pusher from 'pusher-js';
import type { Auth } from '@/types/auth';

declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

// Hand-written: Typefinder emits SharedPageProps only beside #[TypefinderPage]
// pages, which this app does not use (https://github.com/pentacore/Laravel-Typefinder/issues/231).
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            ai: { enabled: boolean; max_attachments: number };
            integrations: {
                seerr: boolean;
                prowlarr: boolean;
                whisparr: boolean;
            };
            nav: {
                pendingActions: number;
                pendingActionsVersion?: number;
                activeSessions: number;
                unreadNotifications: number;
                libraryIntervention: number;
                sabnzbdDownloads: { queued: number; completed: number };
                replacementAttention: number;
                wantedMissing: number;
            };
            sidebarOpen: boolean;
            version: {
                current: string;
                latest: string | null;
                updateAvailable: boolean;
            } | null;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
