<script setup lang="ts">
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

interface PoolInitial {
    name: string;
    period: 'daily' | 'weekly' | 'monthly';
    unified: boolean;
    free_input_tokens: number | null;
    free_output_tokens: number | null;
    free_total_tokens: number | null;
    documentation_url: string | null;
}

const props = defineProps<{
    idPrefix: string;
    errors: Record<string, string>;
    pool?: PoolInitial;
}>();

const PERIODS = [
    { value: 'daily', label: 'Daily' },
    { value: 'weekly', label: 'Weekly' },
    { value: 'monthly', label: 'Monthly' },
];

const unified = ref(props.pool?.unified ?? false);
const period = ref<PoolInitial['period']>(props.pool?.period ?? 'monthly');
</script>

<template>
    <div class="space-y-4">
        <div class="space-y-2">
            <Label :for="`${idPrefix}_name`">Name</Label>
            <Input
                :id="`${idPrefix}_name`"
                name="name"
                placeholder="Gemini free tier"
                :default-value="pool?.name"
            />
            <InputError :message="errors.name" />
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div class="space-y-2">
                <Label :for="`${idPrefix}_period`">Reset period</Label>
                <Select
                    :id="`${idPrefix}_period`"
                    v-model="period"
                    name="period"
                >
                    <SelectTrigger class="h-9 w-full text-sm">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="p in PERIODS"
                            :key="p.value"
                            :value="p.value"
                        >
                            {{ p.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="errors.period" />
            </div>
            <div class="space-y-2">
                <Label :for="`${idPrefix}_unified`">Unified budget</Label>
                <label class="flex h-9 items-center gap-2 text-sm">
                    <input type="hidden" name="unified" value="0" />
                    <input
                        :id="`${idPrefix}_unified`"
                        v-model="unified"
                        type="checkbox"
                        name="unified"
                        value="1"
                        class="size-4"
                    />
                    input + output share one budget
                </label>
                <InputError :message="errors.unified" />
            </div>
        </div>
        <div v-if="unified" class="space-y-2">
            <Label :for="`${idPrefix}_free_total`">Free tokens / period</Label>
            <Input
                :id="`${idPrefix}_free_total`"
                name="free_total_tokens"
                type="number"
                min="0"
                :default-value="pool?.free_total_tokens ?? ''"
            />
            <InputError :message="errors.free_total_tokens" />
        </div>
        <div v-else class="grid grid-cols-2 gap-4">
            <div class="space-y-2">
                <Label :for="`${idPrefix}_free_input`"
                    >Free input / period</Label
                >
                <Input
                    :id="`${idPrefix}_free_input`"
                    name="free_input_tokens"
                    type="number"
                    min="0"
                    placeholder="leave blank if none"
                    :default-value="pool?.free_input_tokens ?? ''"
                />
                <InputError :message="errors.free_input_tokens" />
            </div>
            <div class="space-y-2">
                <Label :for="`${idPrefix}_free_output`"
                    >Free output / period</Label
                >
                <Input
                    :id="`${idPrefix}_free_output`"
                    name="free_output_tokens"
                    type="number"
                    min="0"
                    placeholder="leave blank if none"
                    :default-value="pool?.free_output_tokens ?? ''"
                />
                <InputError :message="errors.free_output_tokens" />
            </div>
        </div>
        <div class="space-y-2">
            <Label :for="`${idPrefix}_doc_url`">Documentation URL</Label>
            <Input
                :id="`${idPrefix}_doc_url`"
                name="documentation_url"
                type="url"
                placeholder="https://…"
                :default-value="pool?.documentation_url ?? ''"
            />
            <InputError :message="errors.documentation_url" />
        </div>
    </div>
</template>
