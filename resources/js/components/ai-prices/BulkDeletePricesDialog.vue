<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AiModelPriceController from '@/actions/App/Http/Controllers/Admin/AiModelPriceController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const PREVIEW_LIMIT = 5;

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    prices: Array<{ id: number; provider: string; model: string }>;
}>();

const emit = defineEmits<{ deleted: [] }>();

const processing = ref(false);
const formError = ref<string | null>(null);

const preview = computed(() => props.prices.slice(0, PREVIEW_LIMIT));
const remaining = computed(() =>
    Math.max(props.prices.length - PREVIEW_LIMIT, 0),
);

function confirmDelete(): void {
    router.visit(AiModelPriceController.bulkDestroy.url(), {
        method: 'delete',
        data: { ids: props.prices.map((price) => price.id) },
        preserveScroll: true,
        onStart: () => {
            processing.value = true;
            formError.value = null;
        },
        onFinish: () => {
            processing.value = false;
        },
        onError: (errors) => {
            formError.value = Object.values(errors)[0] ?? null;
        },
        onSuccess: () => {
            open.value = false;
            emit('deleted');
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-md" data-bulk-delete-dialog>
            <DialogHeader>
                <DialogTitle>
                    Remove {{ prices.length }}
                    {{ prices.length === 1 ? 'model price' : 'model prices' }}?
                </DialogTitle>
                <DialogDescription>
                    A price refresh re-adds any model the pricing feeds still
                    list.
                </DialogDescription>
            </DialogHeader>
            <ul class="space-y-1 text-[12.5px]">
                <li
                    v-for="price in preview"
                    :key="price.id"
                    class="font-mono-tabular"
                >
                    {{ price.provider }} / {{ price.model }}
                </li>
                <li v-if="remaining > 0" class="text-fg-subtle">
                    and {{ remaining }} more
                </li>
            </ul>
            <InputError :message="formError ?? undefined" />
            <DialogFooter>
                <Button variant="outline" @click="open = false">Cancel</Button>
                <Button
                    variant="destructive"
                    :disabled="processing"
                    data-bulk-delete-confirm
                    @click="confirmDelete"
                >
                    Remove
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
