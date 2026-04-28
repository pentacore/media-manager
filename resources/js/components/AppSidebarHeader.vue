<script setup lang="ts">
import { Bell, ChevronRight, Command, Search, Sparkles } from 'lucide-vue-next';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import ConnectionStatusIndicator from '@/components/ConnectionStatusIndicator.vue';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useCommandPalette } from '@/composables/useCommandPalette';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const palette = useCommandPalette();
</script>

<template>
    <header
        class="sticky top-0 z-10 flex h-13 shrink-0 items-center gap-3 border-b border-border bg-background/70 px-6 backdrop-blur transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <SidebarTrigger class="-ml-1" />
        <ChevronRight
            v-if="breadcrumbs.length > 0"
            class="size-3.5 text-fg-subtle"
        />
        <template v-if="breadcrumbs && breadcrumbs.length > 0">
            <Breadcrumbs :breadcrumbs="breadcrumbs" />
        </template>

        <div class="ml-auto flex items-center gap-2">
            <button
                type="button"
                class="hidden h-7 min-w-[240px] items-center gap-2 rounded-md border border-border bg-bg-elev px-3 text-xs text-fg-subtle transition-colors hover:border-border-strong hover:text-muted-foreground sm:flex"
                @click="palette.show()"
            >
                <Search class="size-3.5" />
                <span class="flex-1 text-left"
                    >Search media, requests, actions…</span
                >
                <kbd
                    class="font-mono-tabular flex items-center gap-0.5 rounded border border-border bg-card px-1 py-px text-[10px] text-muted-foreground"
                >
                    <Command class="size-2.5" />K
                </kbd>
            </button>
            <Button
                variant="ghost"
                size="icon"
                class="size-8"
                title="Notifications"
            >
                <Bell class="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                class="size-8"
                title="AI assistant"
            >
                <Sparkles class="size-4" />
            </Button>
            <ConnectionStatusIndicator />
        </div>
    </header>
</template>
