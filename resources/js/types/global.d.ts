import type Pusher from 'pusher-js';
import type { SharedPageProps } from '@/typefinder';
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

// Shared props are SharedPageProps, generated from the documented share() of
// HandleInertiaRequests, with `auth` narrowed (see Auth); the index signature
// covers each page's own props.
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: Omit<SharedPageProps, 'auth'> & {
            auth: Auth;
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
