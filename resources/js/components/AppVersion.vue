<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

type VersionInfo = {
    current: string;
    latest: string | null;
    updateAvailable: boolean;
};

const page = usePage();

const version = computed(
    () =>
        (page.props as unknown as { version?: VersionInfo | null }).version ??
        null,
);

const label = computed(() =>
    version.value?.current === 'dev' ? 'dev' : `v${version.value?.current}`,
);
</script>

<template>
    <div
        v-if="version"
        class="px-2 py-1 text-xs text-muted-foreground group-data-[collapsible=icon]:hidden"
    >
        <a
            v-if="version.updateAvailable"
            href="https://github.com/pentacore/media-manager/releases"
            target="_blank"
            rel="noopener noreferrer"
            class="flex items-center gap-1.5 hover:text-foreground"
            :title="`v${version.latest} available`"
        >
            <span>{{ label }}</span>
            <span
                class="size-1.5 rounded-full bg-primary"
                aria-hidden="true"
            />
            <span class="sr-only">update available</span>
        </a>
        <span v-else>{{ label }}</span>
    </div>
</template>
