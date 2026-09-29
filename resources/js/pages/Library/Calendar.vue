<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import { AlertCircle, ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import CalendarController from '@/actions/App/Http/Controllers/Library/CalendarController';
import { CalendarAgenda, CalendarMonthGrid } from '@/components/calendar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useDateTime } from '@/composables/useDateTime';
import { dayKey, monthGrid, shiftMonth, utcDateKey } from '@/lib/calendar';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { CalendarItem, CalendarPayload } from '@/types';

const props = defineProps<{ month: string; calendar?: CalendarPayload }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Calendar', href: CalendarController.url() },
        ],
    },
});

const { preferences } = useDateTime();
const view = ref<'month' | 'agenda'>('month');
const showTv = ref(true);
const showMovies = ref(true);
const monitoredOnly = ref(false);

// Agenda by default on small screens; decided after mount so SSR markup matches.
onMounted(() => {
    if (window.matchMedia('(max-width: 767px)').matches) {
        view.value = 'agenda';
    }
});

const timezone = computed(() => preferences.value.timezone);
const firstDayOfWeek = computed(() => preferences.value.first_day_of_week);
const weeks = computed(() => monthGrid(props.month, firstDayOfWeek.value));
const today = computed(() => dayKey(new Date().toISOString(), timezone.value));
const weekdayLabels = computed(() => {
    const monday = Date.UTC(2024, 0, 1); // a Monday

    return Array.from({ length: 7 }, (_, index) =>
        new Intl.DateTimeFormat('en-GB', { weekday: 'short', timeZone: 'UTC' }).format(
            new Date(monday + ((firstDayOfWeek.value + 6 + index) % 7) * 86_400_000),
        ),
    );
});

const visibleItems = computed<CalendarItem[]>(() =>
    (props.calendar?.items ?? []).filter(
        (item) =>
            (item.service === 'sonarr' ? showTv.value : showMovies.value) &&
            (!monitoredOnly.value || item.monitored),
    ),
);

const itemsByDay = computed(() => {
    const grouped: Record<string, CalendarItem[]> = {};

    for (const item of visibleItems.value) {
        const day =
            item.service === 'radarr'
                ? utcDateKey(item.air_date_utc)
                : dayKey(item.air_date_utc, timezone.value);

        (grouped[day] ??= []).push(item);
    }

    return grouped;
});

const agendaDays = computed(() =>
    Object.keys(itemsByDay.value)
        .filter((day) => day.startsWith(props.month))
        .sort()
        .map((day) => ({ day, items: itemsByDay.value[day] })),
);

const monthLabel = computed(() =>
    new Intl.DateTimeFormat('en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(
        new Date(`${props.month}-01T00:00:00Z`),
    ),
);

function toggleClass(active: boolean): string {
    return cn(
        'inline-flex h-7 items-center rounded-md px-2.5 text-xs font-medium transition-colors',
        active ? 'bg-accent text-accent-foreground' : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
    );
}
</script>

<template>
    <Head title="Calendar" />

    <div class="flex flex-col gap-4 p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <Button
                    variant="ghost"
                    size="icon-sm"
                    data-calendar-prev
                    @click="router.get(CalendarController.url({ query: { month: shiftMonth(month, -1) } }))"
                ><ChevronLeft class="size-4" /><span class="sr-only">Previous month</span></Button>
                <h1 class="text-[20px] leading-tight font-semibold tracking-tight" data-calendar-month-label>{{ monthLabel }}</h1>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    data-calendar-next
                    @click="router.get(CalendarController.url({ query: { month: shiftMonth(month, 1) } }))"
                ><ChevronRight class="size-4" /><span class="sr-only">Next month</span></Button>
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
                <button type="button" :class="toggleClass(view === 'month')" data-calendar-view="month" @click="view = 'month'">Month</button>
                <button type="button" :class="toggleClass(view === 'agenda')" data-calendar-view="agenda" @click="view = 'agenda'">Agenda</button>
                <span class="mx-1 h-4 w-px bg-border" />
                <button type="button" :class="toggleClass(showTv)" :aria-pressed="showTv" data-calendar-filter="tv" @click="showTv = !showTv">TV</button>
                <button type="button" :class="toggleClass(showMovies)" :aria-pressed="showMovies" data-calendar-filter="movies" @click="showMovies = !showMovies">Movies</button>
                <button type="button" :class="toggleClass(monitoredOnly)" :aria-pressed="monitoredOnly" data-calendar-filter="monitored" @click="monitoredOnly = !monitoredOnly">Monitored only</button>
            </div>
        </div>

        <Deferred data="calendar">
            <template #fallback>
                <Skeleton class="h-[480px] w-full rounded-xl" />
            </template>

            <Alert v-if="props.calendar && props.calendar.failures.length > 0" variant="destructive" data-calendar-failures>
                <AlertCircle class="size-4" />
                <AlertTitle>Some services did not answer</AlertTitle>
                <AlertDescription>
                    <span v-for="failure in props.calendar.failures" :key="`${failure.service}-${failure.instance}`" class="block">
                        {{ failure.service }} ({{ failure.instance }}) is unreachable — showing everything else.
                    </span>
                </AlertDescription>
            </Alert>

            <CalendarMonthGrid
                v-if="view === 'month'"
                :weeks="weeks"
                :month="month"
                :items-by-day="itemsByDay"
                :today="today"
                :weekday-labels="weekdayLabels"
            />
            <CalendarAgenda v-else :days="agendaDays" />
        </Deferred>
    </div>
</template>
