<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AiModelPriceController from '@/actions/App/Http/Controllers/Admin/AiModelPriceController';
import InputError from '@/components/InputError.vue';
import { RateLimitEditor } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { RateLimitDraft } from './types';

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    ids: number[];
    pools: Array<{ id: number; name: string }>;
    rateLimitMetrics: Array<{ value: string; label: string }>;
    rateLimitPeriods: Array<{ value: string; label: string }>;
}>();

const emit = defineEmits<{ saved: [] }>();

const UNCHANGED = 'unchanged';

// Every setting starts at "unchanged" and is only sent once the admin picks a
// value, so the backend leaves the untouched ones alone on every row.
const automaticUpdates = ref<'unchanged' | 'on' | 'off'>(UNCHANGED);
const poolId = ref(UNCHANGED);
const replaceRateLimits = ref(false);
const rateLimits = ref<RateLimitDraft[]>([]);
const processing = ref(false);
const errors = ref<Record<string, string>>({});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    automaticUpdates.value = UNCHANGED;
    poolId.value = UNCHANGED;
    replaceRateLimits.value = false;
    rateLimits.value = [];
    errors.value = {};
});

const changesSomething = computed(
    () =>
        automaticUpdates.value !== UNCHANGED ||
        poolId.value !== UNCHANGED ||
        replaceRateLimits.value,
);

type BulkUpdatePayload = {
    ids: number[];
    automatic_updates_enabled?: boolean;
    free_usage_pool_id?: number | null;
    rate_limits?: Array<{
        metric: string;
        period: string;
        limit_value: number | null;
    }>;
};

function payload(): BulkUpdatePayload {
    const data: BulkUpdatePayload = { ids: props.ids };

    if (automaticUpdates.value !== UNCHANGED) {
        data.automatic_updates_enabled = automaticUpdates.value === 'on';
    }

    if (poolId.value !== UNCHANGED) {
        data.free_usage_pool_id =
            poolId.value === 'none' ? null : Number(poolId.value);
    }

    if (replaceRateLimits.value) {
        data.rate_limits = rateLimits.value.map((limit) => ({
            metric: limit.metric,
            period: limit.period,
            limit_value: limit.limit_value ?? null,
        }));
    }

    return data;
}

function submit(): void {
    router.put(AiModelPriceController.bulkUpdate.url(), payload(), {
        preserveScroll: true,
        onStart: () => {
            processing.value = true;
            errors.value = {};
        },
        onFinish: () => {
            processing.value = false;
        },
        onError: (validationErrors) => {
            errors.value = validationErrors;
        },
        onSuccess: () => {
            open.value = false;
            emit('saved');
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-lg" data-bulk-edit-dialog>
            <DialogHeader>
                <DialogTitle>
                    Edit {{ ids.length }}
                    {{ ids.length === 1 ? 'model' : 'models' }}
                </DialogTitle>
                <DialogDescription>
                    Only the settings you change are applied. Everything left
                    unchanged keeps each model's current value.
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="bulk_automatic_updates">
                        Automatic pricing updates
                    </Label>
                    <Select
                        id="bulk_automatic_updates"
                        v-model="automaticUpdates"
                    >
                        <SelectTrigger
                            class="h-9 w-full text-sm"
                            data-bulk-automatic-updates
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                :value="UNCHANGED"
                                aria-label="Leave automatic updates unchanged"
                            >
                                Leave unchanged
                            </SelectItem>
                            <SelectItem
                                value="on"
                                aria-label="Automatic updates on"
                            >
                                On — kept in sync online
                            </SelectItem>
                            <SelectItem
                                value="off"
                                aria-label="Automatic updates off"
                            >
                                Off — locked to manual price
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="errors.automatic_updates_enabled" />
                </div>
                <div class="space-y-2">
                    <Label for="bulk_free_usage_pool_id">Free usage pool</Label>
                    <Select id="bulk_free_usage_pool_id" v-model="poolId">
                        <SelectTrigger
                            class="h-9 w-full text-sm"
                            data-bulk-free-usage-pool
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                :value="UNCHANGED"
                                aria-label="Leave free usage pool unchanged"
                            >
                                Leave unchanged
                            </SelectItem>
                            <SelectItem value="none" aria-label="No pool">
                                No pool
                            </SelectItem>
                            <SelectItem
                                v-for="pool in pools"
                                :key="pool.id"
                                :value="String(pool.id)"
                                :aria-label="pool.name"
                            >
                                {{ pool.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="errors.free_usage_pool_id" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Label
                        class="col-span-2 flex items-center gap-2 font-normal"
                    >
                        <Checkbox
                            v-model="replaceRateLimits"
                            data-bulk-replace-rate-limits
                        />
                        Replace rate limits
                    </Label>
                    <template v-if="replaceRateLimits">
                        <RateLimitEditor
                            v-model="rateLimits"
                            :metrics="rateLimitMetrics"
                            :periods="rateLimitPeriods"
                            :errors="errors"
                        />
                        <p class="col-span-2 text-[11px] text-fg-subtle">
                            Every selected model gets exactly these limits. Save
                            an empty list to remove all their limits.
                        </p>
                    </template>
                </div>
                <InputError :message="errors.ids" />
                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="processing || !changesSomething"
                        data-bulk-edit-submit
                    >
                        Apply to {{ ids.length }}
                        {{ ids.length === 1 ? 'model' : 'models' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
