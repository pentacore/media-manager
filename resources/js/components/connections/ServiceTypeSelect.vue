<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { ServiceTypeOption } from './types';

defineProps<{
    serviceTypes: ServiceTypeOption[];
    defaultValue?: string;
    error?: string;
}>();

const type = defineModel<string>({ required: true });
</script>

<template>
    <div class="space-y-2">
        <Label for="service_type">Service Type</Label>
        <Select name="type" v-model="type" :default-value="defaultValue">
            <SelectTrigger id="service_type" class="w-full">
                <SelectValue placeholder="Select a service type" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="serviceType in serviceTypes"
                    :key="serviceType.value"
                    :value="serviceType.value"
                    :aria-label="serviceType.label"
                >
                    {{ serviceType.label }}
                </SelectItem>
            </SelectContent>
        </Select>
        <InputError :message="error" />
    </div>
</template>
