<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { BulkActionBar } from '@/components/bulk';
import { Button } from '@/components/ui/button';

/**
 * Test fixture, reached only by a route `BulkBarHeightTest` registers: no
 * product page shows two bulk bars at once, and the bars' shared height
 * (`--bulk-bar-height`) has to hold for two.
 */
const first = ref(0);
const second = ref(0);
</script>

<template>
    <Head title="Bulk bars" />
    <div class="flex flex-col gap-4 p-4" data-test="bulk-bars-fixture">
        <div class="flex gap-2">
            <Button data-test="first-toggle" @click="first = first ? 0 : 1">
                First
            </Button>
            <Button data-test="second-toggle" @click="second = second ? 0 : 1">
                Second
            </Button>
        </div>
        <div class="h-[2000px]" />
        <BulkActionBar :count="first" data-test="first-bar" @clear="first = 0">
            <Button size="sm">Act</Button>
        </BulkActionBar>
        <BulkActionBar
            :count="second"
            data-test="second-bar"
            @clear="second = 0"
        >
            <div class="h-24 w-full" data-test="second-tall" />
        </BulkActionBar>
    </div>
</template>
