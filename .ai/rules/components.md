---
paths:
  - 'resources/js/components/**'
---

# Components

## Component placement and imports
Put shadcn-vue primitives in `components/ui/&lt;kebab-folder&gt;/`, house design-system primitives in `components/mm/`, feature components in `components/&lt;feature&gt;/`, and app-shell components at the root. Name files PascalCase and import via the folder's `index.ts` barrel, not deep file paths. Icons are `@lucide/vue` named imports sized with Tailwind classes.

## Calendar days open a day popover
In `CalendarMonthGrid` a day with items is one `PopoverTrigger` button (`[data-calendar-day-open]`) holding the day number, at most three compact rows and "+N more"; its `PopoverContent` (`[data-calendar-day-popover]`, portalled to `<body>`) lists every item of the day as the full `CalendarItem` row with its Search/Monitor actions. Compact rows are plain `<span>` text, never links or buttons, because they sit inside the trigger button; put any new per-item control on the full row. A day without items has no trigger. `tests/Browser/CalendarTest.php` pins it.

## Page headers use PageHeader
A page's hero header (title block on the left, actions on the right) is `PageHeader` from `@/components/mm`, never a hand-rolled `flex … justify-between` row. Pass the original elements into its slots: `eyebrow` (the `mb-1.5` breadcrumb line), `title` (the `h1`), `description`, `filters` (controls that stay visible on a phone), `secondaryActions` (inline buttons, desktop only), `primaryAction` (the one action a phone keeps) and `menu` (`DropdownMenuItem`s mirroring `secondaryActions` for the ⋯ menu below `sm`; same handlers and URLs, each with `data-page-header-item="<key>"`). Put a condition that removes a whole slot on its `<template v-if … #slot>` so the header can drop an empty actions row and never shows an empty menu; set `primary-first` when the primary action came first, `align` from the old row's `items-*`. At `sm` and up the layout is the old row, pixel for pixel. `PageHeaderTest` pins the phone menu, its keyboard model and the desktop row; `MobileLayoutTest` pins every migrated page at 375 px.

## Settings rows use SettingsRow
A label-beside-control settings row is `SettingsRow` from `@/components/mm`: `<SettingsRow><template #label><Field …/></template><control/></SettingsRow>`. Never write an inline `style="grid-template-columns: 200px 1fr"` (or any fixed `grid-template-columns` style): a fixed or `1fr` track has an automatic minimum, so a wide control stretches the row past a phone screen. `SettingsRow` is `grid-cols-1` below `sm` (label above control) and `200px minmax(0,1fr)` with `gap-6` from `sm` up. `SettingsRowTest` pins both widths.
