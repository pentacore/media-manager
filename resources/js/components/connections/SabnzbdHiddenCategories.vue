<script setup lang="ts">
import { computed, ref } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    initialCategories: string[];
}>();

// Backend stores `hidden_categories` as a string[] under settings; the
// form edits a single comma-separated text field for simplicity.
const hiddenCategoriesText = ref<string>(props.initialCategories.join(', '));
const hiddenCategoriesList = computed<string[]>(() =>
    hiddenCategoriesText.value
        .split(',')
        .map((s) => s.trim())
        .filter((s) => s.length > 0),
);
</script>

<template>
    <div class="space-y-3 pt-2" data-hidden-categories>
        <div>
            <Label for="hidden_categories"> Hidden categories </Label>
            <p class="text-sm text-muted-foreground">
                Comma-separated SABnzbd categories whose queue and history rows
                should be hidden everywhere in the app. Leave empty to show
                everything.
            </p>
        </div>
        <Input
            id="hidden_categories"
            v-model="hiddenCategoriesText"
            placeholder="e.g. adult, private"
            autocomplete="off"
        />
        <input
            v-for="category in hiddenCategoriesList"
            :key="category"
            type="hidden"
            name="hidden_categories[]"
            :value="category"
        />
    </div>
</template>
