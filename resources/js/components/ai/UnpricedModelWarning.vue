<script setup lang="ts">
import { AlertTriangle } from '@lucide/vue';
import AiModelPriceController from '@/actions/App/Http/Controllers/Admin/AiModelPriceController';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

defineProps<{
    models: { role: string; provider: string; model: string }[];
}>();
</script>

<template>
    <Alert v-if="models.length > 0" data-unpriced-model-warning>
        <AlertTriangle class="size-4 text-warning" />
        <AlertTitle>Some selected models have no price</AlertTitle>
        <AlertDescription>
            <p>
                The hard monthly budget counts these models as free, so their
                usage never moves spend toward the cap:
            </p>
            <ul class="mt-1 list-disc pl-4">
                <li
                    v-for="item in models"
                    :key="`${item.role}:${item.model}`"
                    data-unpriced-model
                >
                    {{ item.role }}:
                    <span class="font-mono-tabular"
                        >{{ item.provider }}/{{ item.model }}</span
                    >
                </li>
            </ul>
            <p class="mt-1">
                Add a price on the
                <a
                    :href="AiModelPriceController.index.url()"
                    class="underline hover:text-foreground"
                    >model prices</a
                >
                page.
            </p>
        </AlertDescription>
    </Alert>
</template>
