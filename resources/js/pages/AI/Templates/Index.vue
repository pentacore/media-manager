<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { ChatTemplateResource } from '@/typefinder';

defineProps<{
    templates: ChatTemplateResource[];
}>();
</script>

<template>
    <Head title="Assistant templates" />

    <div class="p-6" data-template-list>Assistant templates</div>
</template>
