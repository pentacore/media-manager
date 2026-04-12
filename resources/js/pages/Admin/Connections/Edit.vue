<script setup lang="ts">
defineProps<{
    connection: {
        id: number;
        type: string;
        name: string;
        url: string;
        api_key: string;
        webhook_token: string;
        is_active: boolean;
    };
    serviceTypes: Array<{ value: string; label: string }>;
}>();
</script>

<template>
    <div>
        <h1>Edit Service Connection</h1>
    </div>
</template>
