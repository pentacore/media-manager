<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { PricedModel } from './types';

defineProps<{
    unpriced: boolean;
    pricedModels: PricedModel[];
    assigning: boolean;
}>();

/** The picked catalog model as `provider|model`, or '' for none. */
const assignKey = defineModel<string>({ required: true });

const emit = defineEmits<{ assign: [] }>();
</script>

<template>
    <div class="rounded-md border border-border bg-card p-3" data-usage-assign>
        <div class="mb-2 flex items-center justify-between">
            <Label
                class="text-[11px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
            >
                {{
                    unpriced
                        ? 'Assign price from catalog'
                        : 'Override with a different catalog model'
                }}
            </Label>
        </div>
        <div class="flex items-center gap-2">
            <Select
                :model-value="assignKey"
                @update:model-value="
                    (value) =>
                        (assignKey = typeof value === 'string' ? value : '')
                "
            >
                <SelectTrigger
                    class="h-8 flex-1 text-xs"
                    data-usage-assign-select
                >
                    <SelectValue placeholder="Pick a catalog model…" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="model in pricedModels"
                        :key="`${model.provider}|${model.model}`"
                        :value="`${model.provider}|${model.model}`"
                    >
                        {{ model.provider }} / {{ model.model }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Button
                size="sm"
                class="h-8 text-xs"
                :disabled="!assignKey || assigning"
                data-usage-assign-submit
                @click="emit('assign')"
            >
                {{ assigning ? 'Assigning…' : 'Assign' }}
            </Button>
        </div>
    </div>
</template>
