---
paths:
  - 'resources/js/components/**'
---

# Components

## Component placement and imports
Put shadcn-vue primitives in `components/ui/&lt;kebab-folder&gt;/`, house design-system primitives in `components/mm/`, feature components in `components/&lt;feature&gt;/`, and app-shell components at the root. Name files PascalCase and import via the folder's `index.ts` barrel, not deep file paths. Icons are `@lucide/vue` named imports sized with Tailwind classes.

## Calendar days open a day popover
In `CalendarMonthGrid` a day with items is one `PopoverTrigger` button (`[data-calendar-day-open]`) holding the day number, at most three compact rows and "+N more"; its `PopoverContent` (`[data-calendar-day-popover]`, portalled to `<body>`) lists every item of the day as the full `CalendarItem` row with its Search/Monitor actions. Compact rows are plain `<span>` text, never links or buttons, because they sit inside the trigger button; put any new per-item control on the full row. A day without items has no trigger. `tests/Browser/CalendarTest.php` pins it.
