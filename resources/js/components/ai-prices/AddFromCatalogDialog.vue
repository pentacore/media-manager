<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Library } from '@lucide/vue';
import { ref, watch } from 'vue';
import AiModelCatalogController from '@/actions/App/Http/Controllers/Admin/AiModelCatalogController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import CatalogModelList from './CatalogModelList.vue';

const props = defineProps<{ providers: string[] }>();

const open = ref(false);
const provider = ref(
    props.providers.includes('openrouter')
        ? 'openrouter'
        : (props.providers[0] ?? ''),
);
const selected = ref<string[]>([]);
const processing = ref(false);
const formError = ref<string | null>(null);

watch(provider, () => {
    selected.value = [];
    formError.value = null;
});

function submit(): void {
    router.post(
        AiModelCatalogController.store.url(),
        { provider: provider.value, models: selected.value },
        {
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
                selected.value = [];
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
                data-add-from-catalog
            >
                <Library class="size-3.5" />Add from catalog
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Add from catalog</DialogTitle>
                <DialogDescription>
                    Models from the pricing feeds that aren't in your catalog
                    yet. Added rows keep syncing on every refresh.
                </DialogDescription>
            </DialogHeader>
            <div class="space-y-4">
                <div class="space-y-2">
                    <Label for="catalog_provider">Provider</Label>
                    <Select id="catalog_provider" v-model="provider">
                        <SelectTrigger class="h-9 w-full text-sm">
                            <SelectValue placeholder="Choose a provider" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in providers"
                                :key="option"
                                :value="option"
                                >{{ option }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <!-- Mounted only while open so every open refetches (dropping
                     models just added) and starts with an empty search. -->
                <CatalogModelList
                    v-if="open && provider"
                    v-model:selected="selected"
                    :provider="provider"
                    mode="multi"
                />
                <InputError :message="formError ?? undefined" />
            </div>
            <DialogFooter>
                <Button
                    type="button"
                    :disabled="selected.length === 0 || processing"
                    data-add-from-catalog-submit
                    @click="submit"
                    >Add {{ selected.length }}
                    {{ selected.length === 1 ? 'model' : 'models' }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
