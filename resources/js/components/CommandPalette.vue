<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import {
    Activity as ActivityIcon,
    Film,
    HeartPulse,
    History,
    Inbox,
    LayoutGrid,
    ScrollText,
    Search as SearchIcon,
    Tv,
    Zap,
} from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import ActionRequestController from '@/actions/App/Http/Controllers/Actions/ActionRequestController';
import ActivityLogController from '@/actions/App/Http/Controllers/ActivityLogController';
import NowPlayingController from '@/actions/App/Http/Controllers/Emby/NowPlayingController';
import WatchHistoryController from '@/actions/App/Http/Controllers/Emby/WatchHistoryController';
import MovieController from '@/actions/App/Http/Controllers/Media/MovieController';
import RequestController from '@/actions/App/Http/Controllers/Media/RequestController';
import SearchController from '@/actions/App/Http/Controllers/Media/SearchController';
import SeriesController from '@/actions/App/Http/Controllers/Media/SeriesController';
import ServiceHealthController from '@/actions/App/Http/Controllers/Monitoring/ServiceHealthController';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { dashboard } from '@/routes';

interface QuickLink {
    title: string;
    href: string;
    icon: Component;
}

const { open } = useCommandPalette();
const query = ref('');
const inputEl = useTemplateRef<HTMLInputElement>('inputEl');

const quickLinks = computed<QuickLink[]>(() => [
    { title: 'Dashboard', href: dashboard().url, icon: LayoutGrid },
    {
        title: 'Activity Log',
        href: ActivityLogController.index.url(),
        icon: ScrollText,
    },
    { title: 'Series', href: SeriesController.index.url(), icon: Tv },
    { title: 'Movies', href: MovieController.index.url(), icon: Film },
    { title: 'Requests', href: RequestController.index.url(), icon: Inbox },
    {
        title: 'Now Playing',
        href: NowPlayingController().url,
        icon: ActivityIcon,
    },
    {
        title: 'Watch History',
        href: WatchHistoryController.index.url(),
        icon: History,
    },
    {
        title: 'Service Health',
        href: ServiceHealthController.index.url(),
        icon: HeartPulse,
    },
    {
        title: 'Action Requests',
        href: ActionRequestController.index.url(),
        icon: Zap,
    },
]);

const filteredLinks = computed(() => {
    const q = query.value.trim().toLowerCase();

    if (!q) {
        return quickLinks.value;
    }

    return quickLinks.value.filter((link) =>
        link.title.toLowerCase().includes(q),
    );
});

useEventListener('keydown', (event: KeyboardEvent) => {
    if (event.key === 'k' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        open.value = !open.value;
    }
});

watch(open, (next) => {
    if (next) {
        query.value = '';
        nextTick(() => inputEl.value?.focus());
    }
});

function navigate(href: string): void {
    open.value = false;
    router.visit(href);
}

function submit(): void {
    const trimmed = query.value.trim();

    if (trimmed === '') {
        return;
    }

    open.value = false;
    router.get(SearchController.index.url(), { q: trimmed });
}

function onLinkKey(event: KeyboardEvent): void {
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
        return;
    }

    event.preventDefault();
    const buttons = Array.from(
        document.querySelectorAll<HTMLButtonElement>('[data-palette-link]'),
    );
    const currentIndex = buttons.indexOf(
        document.activeElement as HTMLButtonElement,
    );
    const delta = event.key === 'ArrowDown' ? 1 : -1;
    const nextIndex = (currentIndex + delta + buttons.length) % buttons.length;
    buttons[nextIndex]?.focus();
}

function onInputKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        document
            .querySelector<HTMLButtonElement>('[data-palette-link]')
            ?.focus();
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="top-[20%] gap-0 overflow-hidden p-0 sm:max-w-xl"
            :show-close-button="false"
            @keydown="onLinkKey"
        >
            <DialogTitle class="sr-only">Command palette</DialogTitle>
            <DialogDescription class="sr-only">
                Search your library or jump to a page. Press Enter to search,
                Escape to close.
            </DialogDescription>
            <form
                class="flex items-center gap-2 border-b px-3"
                @submit.prevent="submit"
            >
                <SearchIcon
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    ref="inputEl"
                    v-model="query"
                    type="search"
                    placeholder="Search media or jump to a page…"
                    class="border-0 bg-transparent px-0 shadow-none focus-visible:ring-0"
                    @keydown="onInputKey"
                />
                <kbd
                    class="hidden h-5 items-center rounded border bg-muted px-1.5 text-[10px] font-medium text-muted-foreground sm:inline-flex"
                >
                    Enter
                </kbd>
            </form>

            <div class="max-h-80 overflow-y-auto p-2">
                <p
                    v-if="filteredLinks.length === 0"
                    class="px-3 py-6 text-center text-sm text-muted-foreground"
                >
                    No matching pages — press Enter to search your library.
                </p>
                <ul v-else class="space-y-1">
                    <li v-for="link in filteredLinks" :key="link.href">
                        <button
                            type="button"
                            data-palette-link
                            class="flex w-full items-center gap-2 rounded-sm px-3 py-2 text-left text-sm hover:bg-accent focus:bg-accent focus:outline-none"
                            @click="navigate(link.href)"
                        >
                            <component
                                :is="link.icon"
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            <span>{{ link.title }}</span>
                        </button>
                    </li>
                </ul>
            </div>
        </DialogContent>
    </Dialog>
</template>
