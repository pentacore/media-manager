<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AiOpenRouterModelController from '@/actions/App/Http/Controllers/Admin/AiOpenRouterModelController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { jsonRequest } from '@/composables/useAiChat';

interface OpenRouterModelOption {
    id: string;
    name: string;
    context_length: number | null;
    input_per_mtok: string | null;
    output_per_mtok: string | null;
    added: boolean;
}

defineProps<{
    pricingEnabled: boolean;
}>();

const open = ref(false);
const loading = ref(false);
const submitting = ref(false);
const error = ref<string | null>(null);
const options = ref<OpenRouterModelOption[]>([]);
const search = ref('');
const selected = ref<string[]>([]);

watch(open, async (isOpen) => {
    if (!isOpen || options.value.length > 0) {
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        const data = await jsonRequest<{ models: OpenRouterModelOption[] }>(
            'GET',
            AiOpenRouterModelController.index.url(),
        );
        options.value = data.models;
    } catch (caught) {
        error.value =
            caught instanceof Error
                ? caught.message
                : 'Could not load OpenRouter models.';
    } finally {
        loading.value = false;
    }
});

const filtered = computed<OpenRouterModelOption[]>(() => {
    const term = search.value.trim().toLowerCase();

    if (term === '') {
        return options.value;
    }

    return options.value.filter(
        (option) =>
            option.id.toLowerCase().includes(term) ||
            option.name.toLowerCase().includes(term),
    );
});

function toggle(id: string): void {
    selected.value = selected.value.includes(id)
        ? selected.value.filter((selectedId) => selectedId !== id)
        : [...selected.value, id];
}

function price(value: string | null): string {
    return value === null ? '—' : `$${parseFloat(value).toFixed(2)}`;
}

function submit(): void {
    submitting.value = true;
    router.post(
        AiOpenRouterModelController.store.url(),
        { models: selected.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
                selected.value = [];
                options.value = [];
                search.value = '';
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                variant="outline"
                size="sm"
                class="h-7 gap-1.5 text-xs"
                data-openrouter-picker
            >
                <Plus class="size-3.5" />Add OpenRouter models
            </Button>
        </DialogTrigger>
        <DialogContent class="max-w-2xl" data-openrouter-picker-dialog>
            <DialogHeader>
                <DialogTitle>Add OpenRouter models</DialogTitle>
            </DialogHeader>
            <p v-if="!pricingEnabled" class="text-xs text-warning">
                The OpenRouter pricing source is off in AI settings, so these
                prices will not refresh automatically.
            </p>
            <Input
                name="openrouter_model_search"
                v-model="search"
                type="search"
                class="h-8 text-sm"
                placeholder="Search models, e.g. claude or grok"
            />
            <p v-if="loading" class="text-sm text-muted-foreground">
                Loading OpenRouter models…
            </p>
            <p v-else-if="error" class="text-sm text-destructive">
                {{ error }}
            </p>
            <ul
                v-else
                class="max-h-80 divide-y divide-border overflow-y-auto rounded-md border border-border"
            >
                <li v-for="option in filtered" :key="option.id">
                    <label
                        class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-bg-hover"
                        :class="{ 'cursor-default opacity-60': option.added }"
                        :data-openrouter-model="option.id"
                    >
                        <input
                            type="checkbox"
                            :checked="
                                option.added || selected.includes(option.id)
                            "
                            :disabled="option.added"
                            @change="toggle(option.id)"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="font-mono-tabular block truncate">
                                {{ option.id }}
                            </span>
                            <span
                                v-if="option.name !== option.id"
                                class="block truncate text-xs text-muted-foreground"
                            >
                                {{ option.name }}
                            </span>
                        </span>
                        <span
                            class="text-xs whitespace-nowrap text-muted-foreground"
                        >
                            {{ price(option.input_per_mtok) }} /
                            {{ price(option.output_per_mtok) }} per MTok
                        </span>
                    </label>
                </li>
            </ul>
            <DialogFooter>
                <Button
                    size="sm"
                    :disabled="selected.length === 0 || submitting"
                    data-openrouter-picker-submit
                    @click="submit"
                >
                    Add {{ selected.length || '' }} selected
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
