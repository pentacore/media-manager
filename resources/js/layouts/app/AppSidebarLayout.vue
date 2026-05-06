<script setup lang="ts">
import { onMounted } from 'vue';
import AiChatSheet from '@/components/AiChatSheet.vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import CommandPalette from '@/components/CommandPalette.vue';
import { Toaster } from '@/components/ui/sonner';
import { useNotifications } from '@/composables/useNotifications';
import { usePresenceHeartbeat } from '@/composables/usePresenceHeartbeat';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const { subscribe: subscribeNotifications } = useNotifications();

usePresenceHeartbeat();

onMounted(subscribeNotifications);
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
        <CommandPalette />
        <AiChatSheet />
        <Toaster />
    </AppShell>
</template>
