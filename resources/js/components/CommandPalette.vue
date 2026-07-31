<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Film, Loader2, Search as SearchIcon, Tv } from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import type { Component } from 'vue';
import { computed, nextTick, onMounted, useTemplateRef, watch } from 'vue';
import MovieController from '@/actions/App/Http/Controllers/Media/MovieController';
import SearchController from '@/actions/App/Http/Controllers/Media/SearchController';
import SeriesController from '@/actions/App/Http/Controllers/Media/SeriesController';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { useInstantSearch } from '@/composables/useInstantSearch';
import { useNavItems } from '@/composables/useNavItems';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

type PaletteLink = {
    section: string;
    title: string;
    href: string;
    icon: Component | undefined;
};

const { open } = useCommandPalette();
const {
    query,
    series: instantSeries,
    movies: instantMovies,
    loading: instantLoading,
} = useInstantSearch();
const inputEl = useTemplateRef<HTMLInputElement>('inputEl');

// No counts argument: the palette renders no badges, so it must not open the
// three websocket channels useNavCounts subscribes to.
const navGroups = useNavItems();

/**
 * The sidebar's tree flattened into destinations. Parent rows carry no `href`
 * and only exist to hold children, so they contribute a section heading rather
 * than a row of their own. Badges are deliberately dropped — the palette is a
 * navigation surface, not a status surface.
 */
const paletteLinks = computed<PaletteLink[]>(() => {
    const links: PaletteLink[] = [];

    const push = (section: string, item: NavItem): void => {
        if (item.mobileOnly || item.href === undefined) {
            return;
        }

        links.push({
            section,
            title: item.title,
            href: toUrl(item.href),
            icon: item.icon,
        });
    };

    for (const group of navGroups.value) {
        for (const item of group.items) {
            // Nesting is exactly one level deep, so a single pass suffices.
            if (item.children) {
                for (const child of item.children) {
                    push(`${group.label} · ${item.title}`, child);
                }

                continue;
            }

            push(group.label, item);
        }
    }

    return links;
});

const filteredLinks = computed(() => {
    const q = query.value.trim().toLowerCase();

    if (!q) {
        return paletteLinks.value;
    }

    return paletteLinks.value.filter((link) =>
        link.title.toLowerCase().includes(q),
    );
});

/** Grouped for rendering, preserving `paletteLinks` order. */
const filteredSections = computed<
    Array<{ section: string; links: PaletteLink[] }>
>(() => {
    const sections: Array<{ section: string; links: PaletteLink[] }> = [];

    for (const link of filteredLinks.value) {
        const last = sections.at(-1);

        if (last?.section === link.section) {
            last.links.push(link);

            continue;
        }

        sections.push({ section: link.section, links: [link] });
    }

    return sections;
});

const hasMediaResults = computed(
    () => instantSeries.value.length > 0 || instantMovies.value.length > 0,
);

function navigateMedia(kind: 'series' | 'movie', id: number): void {
    open.value = false;
    router.visit(
        kind === 'series'
            ? SeriesController.show.url({ id })
            : MovieController.show.url({ id }),
    );
}

onMounted(() => {
    // Bind on document with capture so browser-level Cmd/Ctrl+K shortcuts
    // (Chrome/Firefox address-bar search) don't intercept it before us.
    useEventListener(
        () => document,
        'keydown',
        (event: KeyboardEvent) => {
            if (
                (event.key === 'k' || event.key === 'K') &&
                (event.metaKey || event.ctrlKey)
            ) {
                event.preventDefault();
                event.stopPropagation();
                open.value = !open.value;
            }
        },
        { capture: true },
    );
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

            <div class="max-h-96 overflow-y-auto p-2">
                <div v-if="hasMediaResults" class="mb-2 border-b pb-2">
                    <p
                        class="px-3 pb-1 text-[11px] font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Library
                    </p>
                    <ul class="space-y-1">
                        <li
                            v-for="hit in instantSeries"
                            :key="`series-${hit.id}`"
                        >
                            <button
                                type="button"
                                data-palette-link
                                class="flex w-full items-center gap-2 rounded-sm px-3 py-2 text-left text-sm hover:bg-accent focus:bg-accent focus:outline-none"
                                @click="navigateMedia('series', hit.id)"
                            >
                                <Tv
                                    class="size-4 shrink-0 text-muted-foreground"
                                />
                                <span class="truncate">{{ hit.title }}</span>
                                <span
                                    v-if="hit.year"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ hit.year }}
                                </span>
                                <span
                                    class="ml-auto rounded bg-muted px-1.5 py-0.5 text-[10px] tracking-wider text-muted-foreground uppercase"
                                >
                                    Series
                                </span>
                            </button>
                        </li>
                        <li
                            v-for="hit in instantMovies"
                            :key="`movie-${hit.id}`"
                        >
                            <button
                                type="button"
                                data-palette-link
                                class="flex w-full items-center gap-2 rounded-sm px-3 py-2 text-left text-sm hover:bg-accent focus:bg-accent focus:outline-none"
                                @click="navigateMedia('movie', hit.id)"
                            >
                                <Film
                                    class="size-4 shrink-0 text-muted-foreground"
                                />
                                <span class="truncate">{{ hit.title }}</span>
                                <span
                                    v-if="hit.year"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ hit.year }}
                                </span>
                                <span
                                    class="ml-auto rounded bg-muted px-1.5 py-0.5 text-[10px] tracking-wider text-muted-foreground uppercase"
                                >
                                    Movie
                                </span>
                            </button>
                        </li>
                    </ul>
                </div>
                <p
                    v-if="instantLoading && !hasMediaResults"
                    class="flex items-center justify-center gap-2 px-3 py-4 text-sm text-muted-foreground"
                >
                    <Loader2 class="size-4 animate-spin" />
                    Searching library…
                </p>
                <p
                    v-if="
                        filteredLinks.length === 0 &&
                        !hasMediaResults &&
                        !instantLoading
                    "
                    class="px-3 py-6 text-center text-sm text-muted-foreground"
                >
                    No matching pages — press Enter to search your library.
                </p>
                <!--
                    `data-palette-sections` is the assertion hook for the
                    browser tests: it wraps only the section headings and the
                    link titles, so a negative assertion scoped to it cannot be
                    satisfied by the sidebar (same labels), the dialog's own
                    description, the search placeholder, or the instant-result
                    rows above.
                -->
                <div
                    v-if="filteredLinks.length > 0"
                    data-palette-sections
                    class="space-y-2"
                >
                    <div v-for="group in filteredSections" :key="group.section">
                        <p
                            class="px-3 pb-1 text-[11px] font-medium tracking-wider text-muted-foreground uppercase"
                        >
                            {{ group.section }}
                        </p>
                        <ul class="space-y-1">
                            <li v-for="link in group.links" :key="link.href">
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
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
