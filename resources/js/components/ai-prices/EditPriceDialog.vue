<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AiModelPriceController from '@/actions/App/Http/Controllers/Admin/AiModelPriceController';
import InputError from '@/components/InputError.vue';
import { RateLimitEditor, Toggle } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { PoolRow, PriceRow, RateLimitDraft } from './types';

defineProps<{
    pools: PoolRow[];
    rateLimitMetrics: Array<{ value: string; label: string }>;
    rateLimitPeriods: Array<{ value: string; label: string }>;
}>();

/** A copy of the row being edited; null closes the dialog. */
const price = defineModel<PriceRow | null>('price', { required: true });

const editRateLimits = ref<RateLimitDraft[]>([]);

const editPoolId = ref('none');

const REASONING_LEVEL_OPTIONS = [
    'none',
    'low',
    'medium',
    'high',
    'xhigh',
    'max',
] as const;

const editSupportsReasoning = ref<'yes' | 'no' | 'unknown'>('unknown');
const editReasoningLevels = ref<string[]>([]);

// Whether a manually managed row opts into online refreshes. Edit mirrors
// the row's state.
const editAutomaticUpdates = ref(false);

// Tracks whether the admin actually interacted with the edit dialog's toggle
// this session. The hidden `automatic_updates_enabled` field is submitted ONLY
// when touched: an untouched toggle omits the field so a rate edit falls
// through to the backend's price-change locking rule, rather than the prefilled
// value reading as an explicit re-enable.
const editAutomaticUpdatesTouched = ref(false);

function onEditAutomaticUpdatesChange(value: boolean): void {
    editAutomaticUpdates.value = value;
    editAutomaticUpdatesTouched.value = true;
}

// Re-initialised every time a row is opened, as the page's startEdit() did
// before the split, so a second row never inherits the first row's state.
watch(price, (value) => {
    if (value === null) {
        return;
    }

    editPoolId.value =
        value.free_usage_pool_id === null
            ? 'none'
            : String(value.free_usage_pool_id);
    editSupportsReasoning.value =
        value.supports_reasoning === null
            ? 'unknown'
            : value.supports_reasoning
              ? 'yes'
              : 'no';
    editReasoningLevels.value = [...(value.reasoning_levels ?? [])];
    editRateLimits.value = value.rate_limits.map((limit) => ({
        metric: limit.metric,
        period: limit.period,
        limit_value: limit.limit_value,
    }));
    editAutomaticUpdates.value = value.automatic_updates_enabled;
    editAutomaticUpdatesTouched.value = false;
});

function close(): void {
    price.value = null;
}
</script>

<template>
    <Dialog :open="price !== null" @update:open="(v) => !v && close()">
        <DialogContent v-if="price" data-edit-price-dialog>
            <DialogHeader>
                <DialogTitle>
                    Edit {{ price.provider }} / {{ price.model }}
                </DialogTitle>
            </DialogHeader>
            <Form
                v-bind="AiModelPriceController.update.form(price.id)"
                class="space-y-4"
                v-slot="{ errors, processing }"
                @success="close"
            >
                <div class="grid grid-cols-2 gap-4">
                    <div
                        v-for="field in [
                            ['edit_input', 'input_per_mtok', 'Input ($/M)'],
                            ['edit_output', 'output_per_mtok', 'Output ($/M)'],
                            [
                                'edit_cache_r',
                                'cache_read_per_mtok',
                                'Cache Read ($/M)',
                            ],
                            [
                                'edit_cache_w',
                                'cache_write_per_mtok',
                                'Cache Write ($/M)',
                            ],
                        ] as const"
                        :key="field[0]"
                        class="space-y-2"
                    >
                        <Label :for="field[0]">{{ field[2] }}</Label>
                        <Input
                            :id="field[0]"
                            :name="field[1]"
                            type="number"
                            step="0.0001"
                            min="0"
                            :default-value="
                                price[
                                    field[1] as
                                        | 'input_per_mtok'
                                        | 'output_per_mtok'
                                        | 'cache_read_per_mtok'
                                        | 'cache_write_per_mtok'
                                ]
                            "
                        />
                        <InputError
                            :message="
                                errors[
                                    field[1] as
                                        | 'input_per_mtok'
                                        | 'output_per_mtok'
                                        | 'cache_read_per_mtok'
                                        | 'cache_write_per_mtok'
                                ]
                            "
                        />
                    </div>
                    <div class="col-span-2 space-y-2">
                        <Label for="edit_reasoning">Reasoning ($/M)</Label>
                        <Input
                            id="edit_reasoning"
                            name="reasoning_per_mtok"
                            type="number"
                            step="0.0001"
                            min="0"
                            :default-value="price.reasoning_per_mtok"
                        />
                        <InputError :message="errors.reasoning_per_mtok" />
                    </div>
                    <div class="col-span-2 space-y-2" data-search-unit-field>
                        <Label for="edit_search_unit"
                            >Search units ($/1k)</Label
                        >
                        <Input
                            id="edit_search_unit"
                            name="search_unit_per_k"
                            type="number"
                            step="0.0001"
                            min="0"
                            :default-value="price.search_unit_per_k"
                        />
                        <p class="text-[11px] text-fg-subtle">
                            Rerank models only — price per 1,000 searches.
                        </p>
                        <InputError :message="errors.search_unit_per_k" />
                    </div>
                    <div class="col-span-2 space-y-2">
                        <Label for="edit_free_usage_pool_id"
                            >Free usage pool</Label
                        >
                        <input
                            type="hidden"
                            name="free_usage_pool_id"
                            :value="editPoolId === 'none' ? '' : editPoolId"
                        />
                        <Select
                            id="edit_free_usage_pool_id"
                            v-model="editPoolId"
                        >
                            <SelectTrigger
                                class="h-9 w-full text-sm"
                                data-edit-price-pool
                            >
                                <SelectValue placeholder="No pool" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none"> No pool </SelectItem>
                                <SelectItem
                                    v-for="pool in pools"
                                    :key="pool.id"
                                    :value="String(pool.id)"
                                >
                                    {{ pool.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="errors.free_usage_pool_id" />
                    </div>
                    <div class="col-span-2 space-y-2">
                        <Label for="edit_supports_reasoning"
                            >Supports reasoning</Label
                        >
                        <input
                            type="hidden"
                            name="supports_reasoning"
                            :value="editSupportsReasoning"
                        />
                        <Select
                            id="edit_supports_reasoning"
                            v-model="editSupportsReasoning"
                        >
                            <SelectTrigger
                                class="h-9 w-full text-sm"
                                data-price-supports-reasoning
                            >
                                <SelectValue placeholder="Unknown" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="yes">Yes</SelectItem>
                                <SelectItem value="no">No</SelectItem>
                                <SelectItem value="unknown">
                                    Unknown
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="errors.supports_reasoning" />
                        <Label>Accepted reasoning levels</Label>
                        <div class="flex flex-wrap gap-x-4 gap-y-2">
                            <label
                                v-for="level in REASONING_LEVEL_OPTIONS"
                                :key="level"
                                class="flex cursor-pointer items-center gap-2 text-[13px]"
                            >
                                <input
                                    type="checkbox"
                                    :value="level"
                                    v-model="editReasoningLevels"
                                    :data-price-reasoning-level="level"
                                    class="size-4 rounded border-border accent-accent"
                                />
                                {{ level }}
                            </label>
                        </div>
                        <!-- Blank placeholder so an all-unchecked group still submits. -->
                        <input
                            type="hidden"
                            name="reasoning_levels[]"
                            value=""
                        />
                        <input
                            v-for="level in editReasoningLevels"
                            :key="level"
                            type="hidden"
                            name="reasoning_levels[]"
                            :value="level"
                        />
                        <p class="text-[11px] text-fg-subtle">
                            Filled by the pricing feeds. Feed syncs overwrite it
                            unless automatic updates are off.
                        </p>
                        <InputError :message="errors.reasoning_levels" />
                    </div>
                    <div class="col-span-2 space-y-2">
                        <Label>Automatic pricing updates</Label>
                        <input
                            v-if="editAutomaticUpdatesTouched"
                            type="hidden"
                            name="automatic_updates_enabled"
                            :value="editAutomaticUpdates ? '1' : '0'"
                        />
                        <Toggle
                            :model-value="editAutomaticUpdates"
                            role="switch"
                            aria-label="Automatic pricing updates"
                            :aria-checked="editAutomaticUpdates"
                            :label="
                                editAutomaticUpdates
                                    ? 'On — kept in sync online'
                                    : 'Off — locked to manual price'
                            "
                            @update:model-value="onEditAutomaticUpdatesChange"
                        />
                        <p class="text-[11px] text-fg-subtle">
                            Editing any rate locks this row so an online refresh
                            won't overwrite your price. Flip this toggle to
                            override: on keeps automatic updates, off forces it
                            locked.
                        </p>
                        <InputError
                            :message="errors.automatic_updates_enabled"
                        />
                    </div>
                    <RateLimitEditor
                        v-model="editRateLimits"
                        :metrics="rateLimitMetrics"
                        :periods="rateLimitPeriods"
                        :errors="errors"
                    />
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="processing"> Save </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
