<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { Plug } from '@lucide/vue';
import { ref } from 'vue';
import ServiceConnectionController from '@/actions/App/Http/Controllers/Admin/ServiceConnectionController';
import { Button } from '@/components/ui/button';
import type { TestConnectionResponse } from './types';

/**
 * Test Connection button and its result line. The default slot renders
 * between the button and the result (Edit uses it for its "enter the API
 * key" hint).
 */
const props = defineProps<{
    type: string;
    url: string;
    apiKey: string;
}>();

const testResult = ref<TestConnectionResponse | null>(null);
const testHttp = useHttp<
    { type: string; url: string; api_key: string },
    TestConnectionResponse
>({ type: '', url: '', api_key: '' });

function testConnection() {
    testResult.value = null;
    testHttp.type = props.type;
    testHttp.url = props.url;
    testHttp.api_key = props.apiKey;
    testHttp.post(ServiceConnectionController.test.url(), {
        onSuccess: (response) => {
            testResult.value = response;
        },
        onError: () => {
            testResult.value = {
                success: false,
                message: 'Connection failed.',
            };
        },
    });
}
</script>

<template>
    <div class="space-y-2">
        <Button
            type="button"
            variant="outline"
            :disabled="!type || !url || !apiKey || testHttp.processing"
            data-connection-test
            @click="testConnection"
        >
            <Plug class="mr-2 size-4" />
            {{ testHttp.processing ? 'Testing...' : 'Test Connection' }}
        </Button>
        <slot />
        <p
            v-if="testResult?.success"
            class="text-sm text-green-600 dark:text-green-400"
            data-connection-test-result
        >
            {{ testResult.message }}
            <span v-if="testResult.version"> (v{{ testResult.version }})</span>
        </p>
        <p
            v-else-if="testResult && !testResult.success"
            class="text-sm text-destructive"
            data-connection-test-result
        >
            {{ testResult.message }}
        </p>
    </div>
</template>
