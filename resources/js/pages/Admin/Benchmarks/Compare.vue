<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    comparison: { runs: Array<{ id: number; name: string }> };
}>();
</script>

<template>
    <Head title="Compare runs" />

    <div class="flex flex-col gap-4 p-5" data-compare-page>
        <h1 class="text-[20px] font-semibold">Compare runs</h1>
        <p class="text-[12.5px] text-muted-foreground">
            {{ comparison.runs.map((run) => run.name).join(' → ') }}
        </p>
    </div>
</template>
