<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import type { HTMLAttributes } from 'vue';
import AiModelCatalogController from '@/actions/App/Http/Controllers/Admin/AiModelCatalogController';
import AiSettingsController from '@/actions/App/Http/Controllers/Admin/AiSettingsController';
import { Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { jsonRequest } from '@/composables/useAiChat';
import { cn } from '@/lib/utils';
import { SOURCE_LABELS } from './pricingSources';
import type { CatalogModelOption } from './types';

const props = withDefaults(
    defineProps<{
        provider: string;
        mode: 'single' | 'multi';
        selected?: string[];
        class?: HTMLAttributes['class'];
    }>(),
    { selected: () => [] },
);

const emit = defineEmits<{
    select: [option: CatalogModelOption];
    'update:selected': [models: string[]];
}>();

const options = ref<CatalogModelOption[]>([]);
// False when no enabled pricing feed prices the provider at all, as opposed
// to every catalog model already having a row.
const covered = ref(true);
const loading = ref(false);
const error = ref<string | null>(null);
const search = ref('');

// Guards against a slow response for a previous provider overwriting the
// list after the admin already switched providers.
let latestRequest = 0;

async function load(): Promise<void> {
    const request = ++latestRequest;
    loading.value = true;
    error.value = null;
    options.value = [];
    covered.value = true;

    try {
        const response = await jsonRequest<{
            models: CatalogModelOption[];
            covered: boolean;
        }>('GET', AiModelCatalogController.index.url(props.provider));

        if (request === latestRequest) {
            options.value = response.models;
            covered.value = response.covered;
        }
    } catch (caught) {
        if (request === latestRequest) {
            error.value =
                caught instanceof Error
                    ? caught.message
                    : 'Could not load the catalog.';
        }
    } finally {
        if (request === latestRequest) {
            loading.value = false;
        }
    }
}

onMounted(load);

watch(
    () => props.provider,
    () => {
        search.value = '';
        void load();
    },
);

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();

    return term === ''
        ? options.value
        : options.value.filter((option) =>
              option.model.toLowerCase().includes(term),
          );
});

function isSelected(model: string): boolean {
    return props.selected.includes(model);
}

function choose(option: CatalogModelOption): void {
    if (props.mode === 'single') {
        emit('select', option);

        return;
    }

    emit(
        'update:selected',
        isSelected(option.model)
            ? props.selected.filter((model) => model !== option.model)
            : [...props.selected, option.model],
    );
}

const rateFormatter = new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 4,
});

function formatRate(value: string | null): string {
    return value === null ? '—' : `$${rateFormatter.format(Number(value))}`;
}
</script>

<template>
    <div :class="cn('space-y-2', props.class)" data-catalog-list>
        <Input
            v-model="search"
            placeholder="Search models…"
            class="h-8 text-sm"
            data-catalog-search
        />
        <div v-if="loading" class="space-y-1.5" data-catalog-loading>
            <Skeleton v-for="row in 5" :key="row" class="h-8 w-full" />
        </div>
        <div
            v-else-if="error"
            class="flex items-center justify-between gap-2 rounded-md border border-border p-3 text-[13px]"
            data-catalog-error
        >
            <span class="text-muted-foreground">{{ error }}</span>
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="h-7 text-xs"
                @click="load"
                >Retry</Button
            >
        </div>
        <p
            v-else-if="!covered"
            class="text-[13px] text-muted-foreground"
            data-catalog-uncovered
        >
            No enabled pricing feed covers this provider. Turn on a pricing
            source in
            <Link
                :href="AiSettingsController.index.url()"
                class="text-primary underline underline-offset-2"
                data-catalog-uncovered-settings-link
                >AI settings</Link
            >.
        </p>
        <p
            v-else-if="options.length === 0"
            class="text-[13px] text-muted-foreground"
            data-catalog-empty
        >
            Every catalog model for this provider is already added.
        </p>
        <p
            v-else-if="filtered.length === 0"
            class="text-[13px] text-muted-foreground"
        >
            No models match.
        </p>
        <ul
            v-else
            class="max-h-64 divide-y divide-border overflow-y-auto rounded-md border border-border"
        >
            <li v-for="option in filtered" :key="option.model">
                <button
                    type="button"
                    class="flex w-full items-center gap-2.5 px-3 py-2 text-left hover:bg-bg-hover"
                    :aria-pressed="
                        mode === 'multi' ? isSelected(option.model) : undefined
                    "
                    :data-catalog-row="option.model"
                    @click="choose(option)"
                >
                    <span
                        v-if="mode === 'multi'"
                        :class="
                            cn(
                                'flex size-4 shrink-0 items-center justify-center rounded-sm border border-border',
                                isSelected(option.model) &&
                                    'border-primary bg-primary text-primary-foreground',
                            )
                        "
                    >
                        <Check v-if="isSelected(option.model)" class="size-3" />
                    </span>
                    <span
                        class="font-mono-tabular min-w-0 flex-1 truncate text-[13px]"
                        >{{ option.model }}</span
                    >
                    <Pill v-if="option.tiered" variant="warn">tiered</Pill>
                    <span class="shrink-0 text-xs text-muted-foreground"
                        >{{ formatRate(option.prices.input_per_mtok) }} /
                        {{ formatRate(option.prices.output_per_mtok) }}</span
                    >
                    <span
                        class="hidden shrink-0 text-[11px] text-fg-subtle sm:inline"
                        >{{ SOURCE_LABELS[option.source] }}</span
                    >
                </button>
            </li>
        </ul>
    </div>
</template>
