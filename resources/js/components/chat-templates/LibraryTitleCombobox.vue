<script setup lang="ts">
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import ChatTemplateLibraryController from '@/actions/App/Http/Controllers/AI/ChatTemplateLibraryController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { jsonRequest } from '@/lib/http';
import type { LibraryHit } from './types';

const props = defineProps<{
    type: 'series' | 'movie';
    modelValue: LibraryHit | null;
    fieldName: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: LibraryHit | null): void;
}>();

const query = ref('');
const results = ref<LibraryHit[]>([]);
const loading = ref(false);
const failed = ref(false);
let searchRequest = 0;

const search = useDebounceFn(async (term: string): Promise<void> => {
    const request = ++searchRequest;

    if (term.trim() === '') {
        results.value = [];
        loading.value = false;

        return;
    }

    loading.value = true;

    try {
        const response = await jsonRequest<{ items: LibraryHit[] }>(
            'GET',
            ChatTemplateLibraryController.url({
                query: { type: props.type, q: term.trim() },
            }),
        );

        if (request === searchRequest) {
            results.value = response.items;
            failed.value = false;
        }
    } catch {
        if (request === searchRequest) {
            failed.value = true;
        }
    } finally {
        if (request === searchRequest) {
            loading.value = false;
        }
    }
}, 250);

watch(query, (term) => {
    // Show "Searching…" through the debounce, not a premature "No matches".
    loading.value = term.trim() !== '';
    void search(term);
});

function pick(hit: LibraryHit): void {
    emit('update:modelValue', hit);
    query.value = '';
    results.value = [];
}
</script>

<template>
    <div class="grid gap-1.5" :data-template-field="fieldName">
        <div
            v-if="modelValue"
            class="flex items-center gap-2 rounded-md border border-border bg-card px-2 py-1.5"
            data-library-selected
        >
            <img
                v-if="modelValue.poster_url"
                :src="modelValue.poster_url"
                alt=""
                class="h-9 w-6 rounded-sm object-cover"
            />
            <span class="flex-1 text-sm">
                {{ modelValue.title }}
                <span v-if="modelValue.year" class="text-muted-foreground"
                    >({{ modelValue.year }})</span
                >
            </span>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="h-7 text-xs"
                data-library-change
                @click="emit('update:modelValue', null)"
                >Change</Button
            >
        </div>
        <template v-else>
            <Input
                v-model="query"
                class="h-8 text-sm"
                :placeholder="
                    type === 'series'
                        ? 'Search your series…'
                        : 'Search your movies…'
                "
                data-library-search
            />
            <p v-if="loading" class="text-[12px] text-fg-subtle">Searching…</p>
            <p v-else-if="failed" class="text-[12px] text-destructive">
                Library search is unavailable right now.
            </p>
            <p
                v-else-if="query.trim() !== '' && results.length === 0"
                class="text-[12px] text-fg-subtle"
            >
                No matches in your library.
            </p>
            <div
                v-if="results.length > 0"
                class="max-h-56 overflow-y-auto rounded-md border border-border"
            >
                <button
                    v-for="hit in results"
                    :key="hit.id"
                    type="button"
                    class="flex w-full items-center gap-2 px-2 py-1.5 text-left text-sm hover:bg-bg-hover"
                    :data-library-option="hit.id"
                    @click="pick(hit)"
                >
                    <img
                        v-if="hit.poster_url"
                        :src="hit.poster_url"
                        alt=""
                        class="h-9 w-6 rounded-sm object-cover"
                    />
                    <span>
                        {{ hit.title }}
                        <span v-if="hit.year" class="text-muted-foreground"
                            >({{ hit.year }})</span
                        >
                    </span>
                </button>
            </div>
        </template>
    </div>
</template>
