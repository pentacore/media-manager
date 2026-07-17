<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ChatPanel from '@/components/ai/ChatPanel.vue';
import AIChatController from '@/actions/App/Http/Controllers/AI/ChatController';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Assistant', href: dashboard().url },
            { title: 'AI Assistant', href: AIChatController.index.url() },
        ],
    },
});
</script>

<template>
    <Head title="AI Assistant" />

    <ChatPanel variant="page" />
</template>
