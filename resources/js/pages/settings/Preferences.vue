<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Field } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import type {
    DateFormat,
    TimeFormat,
    UserPreferences,
} from '@/types/preferences';
import UserPreferencesController from '@/actions/App/Http/Controllers/Settings/UserPreferencesController';
import { edit } from '@/routes/settings/preferences';

interface TimezoneGroup {
    group: string;
    zones: string[];
}

const props = defineProps<{
    preferences: UserPreferences;
    timezones: TimezoneGroup[];
    options: {
        time_formats: TimeFormat[];
        date_formats: DateFormat[];
        week_starts: number[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Display preferences', href: edit() }],
    },
});

const TIME_FORMAT_LABELS: Record<TimeFormat, string> = {
    '12h': '12-hour (1:30 PM)',
    '24h': '24-hour (13:30)',
};

const DATE_FORMAT_SAMPLES: Record<DateFormat, string> = {
    iso: 'ISO — 2026-05-03',
    us: 'US — 5/3/2026',
    eu: 'EU — 03.05.2026',
    long: 'Long — 3 May 2026',
};

const WEEKDAY_LABELS = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];

// Timezone picker: search filter + browser-detected suggestion when the
// user is still on the UTC default (no explicit pref saved yet).
const browserTimezone = (() => {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
})();

const initialTimezone =
    props.preferences.timezone === 'UTC' && browserTimezone !== 'UTC'
        ? browserTimezone
        : props.preferences.timezone;

const timezoneQuery = ref('');

const filteredTimezones = computed<TimezoneGroup[]>(() => {
    const query = timezoneQuery.value.trim().toLowerCase();

    if (!query) {
        return props.timezones;
    }

    return props.timezones
        .map((g) => ({
            group: g.group,
            zones: g.zones.filter((tz) => tz.toLowerCase().includes(query)),
        }))
        .filter((g) => g.zones.length > 0);
});
</script>

<template>
    <Head title="Display preferences" />

    <h1 class="sr-only">Display preferences</h1>

    <div class="flex flex-col gap-6">
        <div>
            <div class="mb-1.5 text-[13px] text-muted-foreground">
                Settings <span class="text-fg-subtle">/</span> Preferences
            </div>
            <h2 class="text-[18px] leading-tight font-semibold tracking-tight">
                Display preferences
            </h2>
            <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                How dates and times are formatted across the app.
            </p>
        </div>

        <Form
            v-bind="UserPreferencesController.update.form()"
            v-slot="{ errors, processing }"
            :transform="
                (data: Record<string, unknown>) => ({
                    ...data,
                    show_relative_time: Boolean(data.show_relative_time),
                    first_day_of_week: Number(data.first_day_of_week),
                })
            "
            class="rounded-xl border border-border bg-card p-6"
        >
            <div class="flex flex-col gap-5">
                <!-- Time format -->
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Time format"
                        hint="Hour clock used for every time shown in the UI."
                    >
                        <span />
                    </Field>
                    <div class="max-w-[360px]">
                        <Select
                            name="time_format"
                            :default-value="props.preferences.time_format"
                        >
                            <SelectTrigger class="h-8 text-sm">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="value in props.options.time_formats"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ TIME_FORMAT_LABELS[value] }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError
                            :message="errors.time_format"
                            class="mt-1"
                        />
                    </div>
                </div>

                <Separator />

                <!-- Date format -->
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field label="Date format" hint="Layout for full dates.">
                        <span />
                    </Field>
                    <div class="max-w-[360px]">
                        <Select
                            name="date_format"
                            :default-value="props.preferences.date_format"
                        >
                            <SelectTrigger class="h-8 text-sm">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="value in props.options.date_formats"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ DATE_FORMAT_SAMPLES[value] }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError
                            :message="errors.date_format"
                            class="mt-1"
                        />
                    </div>
                </div>

                <Separator />

                <!-- Timezone -->
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Timezone"
                        hint="All times are converted to this zone before display."
                    >
                        <span />
                    </Field>
                    <div class="flex max-w-[360px] flex-col gap-2">
                        <Input
                            v-model="timezoneQuery"
                            class="h-8 text-sm"
                            placeholder="Search (e.g. Stockholm, America)"
                            aria-label="Filter timezones"
                        />
                        <Select
                            name="timezone"
                            :default-value="initialTimezone"
                        >
                            <SelectTrigger class="h-8 text-sm">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup
                                    v-for="g in filteredTimezones"
                                    :key="g.group"
                                >
                                    <SelectLabel>{{ g.group }}</SelectLabel>
                                    <SelectItem
                                        v-for="tz in g.zones"
                                        :key="tz"
                                        :value="tz"
                                    >
                                        {{ tz }}
                                    </SelectItem>
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        <p
                            v-if="
                                props.preferences.timezone === 'UTC' &&
                                browserTimezone !== 'UTC'
                            "
                            class="text-[11.5px] text-muted-foreground"
                        >
                            Detected from your browser:
                            <span class="font-mono-tabular">{{
                                browserTimezone
                            }}</span>
                            — pre-selected. Change above if you prefer a
                            different zone.
                        </p>
                        <InputError :message="errors.timezone" class="mt-1" />
                    </div>
                </div>

                <Separator />

                <!-- First day of week -->
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="First day of week"
                        hint="Used by week pickers and grouped charts."
                    >
                        <span />
                    </Field>
                    <div class="max-w-[360px]">
                        <Select
                            name="first_day_of_week"
                            :default-value="
                                String(props.preferences.first_day_of_week)
                            "
                        >
                            <SelectTrigger class="h-8 text-sm">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="day in props.options.week_starts"
                                    :key="day"
                                    :value="String(day)"
                                >
                                    {{ WEEKDAY_LABELS[day] }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError
                            :message="errors.first_day_of_week"
                            class="mt-1"
                        />
                    </div>
                </div>

                <Separator />

                <!-- Relative time toggle -->
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Relative time"
                        hint="Show '8h ago' for recent entries instead of full timestamps."
                    >
                        <span />
                    </Field>
                    <div>
                        <label
                            class="inline-flex items-center gap-2 text-[13px]"
                        >
                            <input
                                type="checkbox"
                                name="show_relative_time"
                                :checked="props.preferences.show_relative_time"
                                class="size-4 rounded border-border accent-accent"
                            />
                            Show relative time for recent entries
                        </label>
                        <InputError
                            :message="errors.show_relative_time"
                            class="mt-1"
                        />
                    </div>
                </div>

                <div class="flex justify-end pt-2">
                    <Button
                        size="sm"
                        type="submit"
                        :disabled="processing"
                        class="h-8 text-xs"
                        data-test="update-preferences-button"
                    >
                        Save
                    </Button>
                </div>
            </div>
        </Form>
    </div>
</template>
