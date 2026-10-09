<script setup lang="ts">
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

defineProps<{
    defaultValue?: string;
}>();

const version = defineModel<string>({ required: true });
</script>

<template>
    <div class="space-y-2" data-whisparr-version>
        <Label for="whisparr_version">Whisparr Version</Label>
        <Select
            name="whisparr_version"
            v-model="version"
            :default-value="defaultValue"
        >
            <SelectTrigger>
                <SelectValue placeholder="Select Whisparr version" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="v3">v3 (movie-based)</SelectItem>
                <SelectItem value="v2">v2 (Eros / series-based)</SelectItem>
            </SelectContent>
        </Select>
    </div>
</template>
