<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

defineProps<{ heading: string }>();
</script>

<template>
    <section class="space-y-2" aria-busy="true">
        <h2 class="text-[15px] font-semibold tracking-tight">{{ heading }}</h2>
        <div class="flex gap-3 overflow-hidden pb-2">
            <div v-for="n in 8" :key="n" class="flex w-[132px] shrink-0 flex-col gap-1.5">
                <Skeleton class="aspect-[2/3] w-full rounded-md" />
                <Skeleton class="h-4 w-3/4" />
                <Skeleton class="h-3 w-1/2" />
            </div>
        </div>
    </section>
</template>
