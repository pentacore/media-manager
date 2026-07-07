<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

interface RateLimitDraft {
    metric: 'requests' | 'tokens';
    period: 'minute' | 'hour' | 'day';
    limit_value: number | undefined;
}

const modelValue = defineModel<RateLimitDraft[]>({ required: true });

defineProps<{
    metrics: Array<{ value: string; label: string }>;
    periods: Array<{ value: string; label: string }>;
    errors: Record<string, string>;
}>();

function addRateLimit() {
    modelValue.value.push({
        metric: 'requests',
        period: 'minute',
        limit_value: undefined,
    });
}

function removeRateLimit(index: number) {
    modelValue.value.splice(index, 1);
}

function rateLimitErrors(errors: Record<string, string>): string {
    return Object.entries(errors)
        .filter(([key]) => key.startsWith('rate_limits'))
        .map(([, message]) => message)
        .join(' ');
}
</script>

<template>
    <div class="col-span-2 space-y-2">
        <div class="flex items-center justify-between">
            <Label>Rate limits</Label>
            <Button
                type="button"
                variant="outline"
                size="sm"
                @click="addRateLimit"
            >
                <Plus class="h-3.5 w-3.5" /> Add limit
            </Button>
        </div>
        <div
            v-for="(limit, i) in modelValue"
            :key="i"
            class="flex items-center gap-2"
        >
            <Select v-model="limit.metric" :name="`rate_limits[${i}][metric]`">
                <SelectTrigger class="h-9 w-32 text-sm">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="metric in metrics"
                        :key="metric.value"
                        :value="metric.value"
                    >
                        {{ metric.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Select v-model="limit.period" :name="`rate_limits[${i}][period]`">
                <SelectTrigger class="h-9 w-32 text-sm">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="period in periods"
                        :key="period.value"
                        :value="period.value"
                    >
                        {{ period.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Input
                v-model.number="limit.limit_value"
                :name="`rate_limits[${i}][limit_value]`"
                type="number"
                min="1"
                placeholder="Limit"
                class="flex-1"
            />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                @click="removeRateLimit(i)"
            >
                <Trash2 class="h-3.5 w-3.5" />
            </Button>
        </div>
        <InputError :message="rateLimitErrors(errors)" />
    </div>
</template>
