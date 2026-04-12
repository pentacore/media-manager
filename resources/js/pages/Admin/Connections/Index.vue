<script setup lang="ts">
defineProps<{
    connections: Array<{
        id: number;
        type: string;
        name: string;
        url: string;
        is_active: boolean;
        last_seen_at: string | null;
        version: string | null;
    }>;
}>();
</script>

<template>
    <div>
        <h1>Service Connections</h1>
    </div>
</template>
