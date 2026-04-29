<script setup lang="ts">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

type Service =
    | 'sonarr'
    | 'radarr'
    | 'emby'
    | 'seerr'
    | 'jellyseerr'
    | 'prowlarr'
    | 'sabnzbd'
    | 'tmdb'
    | 'trakt'
    | 'system'
    | string;

const props = defineProps<{
    id: Service;
    label?: string;
    class?: HTMLAttributes['class'];
}>();

const colorClass = computed(() => {
    switch (props.id) {
        case 'sonarr':
            return 'text-svc-sonarr';
        case 'radarr':
            return 'text-svc-radarr';
        case 'emby':
            return 'text-svc-emby';
        case 'seerr':
        case 'jellyseerr':
            return 'text-svc-seerr';
        case 'prowlarr':
            return 'text-svc-prowlarr';
        case 'sabnzbd':
            return 'text-svc-sabnzbd';
        default:
            return 'text-muted-foreground';
    }
});

const labelText = computed(() => {
    if (props.label) {
        return props.label;
    }

    switch (props.id) {
        case 'sonarr':
            return 'Sonarr';
        case 'radarr':
            return 'Radarr';
        case 'emby':
            return 'Emby';
        case 'seerr':
        case 'jellyseerr':
            return 'Seerr';
        case 'prowlarr':
            return 'Prowlarr';
        case 'sabnzbd':
            return 'SABnzbd';
        case 'tmdb':
            return 'TMDB';
        case 'trakt':
            return 'Trakt';
        default:
            return props.id;
    }
});
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex items-center gap-1.5 text-xs font-medium',
                colorClass,
                props.class,
            )
        "
    >
        <span class="size-2 rounded-sm bg-current" aria-hidden="true" />
        {{ labelText }}
    </span>
</template>
