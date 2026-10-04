<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { ChatTemplateResource } from '@/typefinder';

defineProps<{
    template: ChatTemplateResource | null;
    prefillBody: string;
    variableTypes: Array<{ value: string; label: string }>;
}>();
</script>

<template>
    <Head title="Edit template" />

    <div class="p-6" data-template-editor>Edit template</div>
</template>
