import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import type { Ref } from 'vue';
import InstantSearchController from '@/actions/App/Http/Controllers/Media/InstantSearchController';

export interface InstantHit {
    id: number;
    title: string;
    year: number | null;
    title_slug: string | null;
    poster_url: string | null;
    kind: 'series' | 'movie';
}

interface InstantSearchPayload {
    series?: InstantHit[];
    movies?: InstantHit[];
    error?: string;
}

interface UseInstantSearch {
    query: Ref<string>;
    series: Ref<InstantHit[]>;
    movies: Ref<InstantHit[]>;
    loading: Ref<boolean>;
    error: Ref<string | null>;
}

export function useInstantSearch(debounceMs = 200): UseInstantSearch {
    const query = ref('');
    const series = ref<InstantHit[]>([]);
    const movies = ref<InstantHit[]>([]);
    const loading = ref(false);
    const error = ref<string | null>(null);

    let abortController: AbortController | null = null;

    const fetchResults = useDebounceFn(async (term: string): Promise<void> => {
        abortController?.abort();

        const trimmed = term.trim();

        if (trimmed === '') {
            series.value = [];
            movies.value = [];
            error.value = null;
            loading.value = false;

            return;
        }

        abortController = new AbortController();
        loading.value = true;
        error.value = null;

        try {
            const url = InstantSearchController({ query: { q: trimmed } }).url;
            const response = await fetch(url, {
                signal: abortController.signal,
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(
                    `Instant search request failed (${response.status})`,
                );
            }

            const data = (await response.json()) as InstantSearchPayload;
            series.value = data.series ?? [];
            movies.value = data.movies ?? [];
            error.value = data.error ?? null;
        } catch (cause) {
            if ((cause as DOMException)?.name === 'AbortError') {
                // A newer request superseded this one and owns the loading
                // flag — clearing it here would hide the newer spinner.
                return;
            }

            error.value = 'Search failed.';
            series.value = [];
            movies.value = [];
            loading.value = false;

            return;
        }

        loading.value = false;
    }, debounceMs);

    watch(query, (next) => {
        void fetchResults(next);
    });

    return { query, series, movies, loading, error };
}
