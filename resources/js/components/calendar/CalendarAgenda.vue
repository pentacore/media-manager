<script setup lang="ts">
import { useDateTime } from '@/composables/useDateTime';
import type { CalendarItem as CalendarItemType } from '@/types';
import CalendarItem from './CalendarItem.vue';

defineProps<{ days: { day: string; items: CalendarItemType[] }[] }>();

const { formatDate } = useDateTime();
</script>

<template>
    <div class="space-y-3" data-calendar-agenda>
        <p
            v-if="days.length === 0"
            class="rounded-xl border border-border bg-card p-6 text-[13px] text-muted-foreground"
        >
            Nothing airing this month.
        </p>
        <section
            v-for="group in days"
            :key="group.day"
            class="overflow-hidden rounded-xl border border-border bg-card"
        >
            <h3
                class="border-b border-border px-3 py-2 text-[12px] font-semibold text-muted-foreground"
            >
                {{ formatDate(`${group.day}T12:00:00Z`) }}
            </h3>
            <CalendarItem
                v-for="item in group.items"
                :key="item.key"
                :item="item"
            />
        </section>
    </div>
</template>
