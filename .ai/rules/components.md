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
A page's hero header (title block on the left, actions on the right) is `PageHeader` from `@/components/mm`, never a new hand-rolled `flex … justify-between` row. Hand-rolled headers that already wrap at 375 px are deliberate exceptions until a later batch adopts them: `Anime/Season.vue`, `Library/Wanted.vue`, `Admin/Benchmarks/Runs/Show.vue`, `Admin/MediaReplacement/Attempts/Show.vue`, `Sonarr/Series/Create.vue`, `components/ai-prices/PricesHeader.vue` and `components/ai-usage/UsageHeader.vue`; the detail toolbars (`SeriesToolbar`, the Radarr and Whisparr show pages) and the chat panel header belong to 3h-2. Pass the original elements into its slots: `eyebrow` (the `mb-1.5` breadcrumb line), `title` (the `h1`), `description`, `filters` (controls that stay visible on a phone), `secondaryActions` (inline buttons, desktop only), `primaryAction` (the one action a phone keeps) and `menu` (`DropdownMenuItem`s mirroring `secondaryActions` for the ⋯ menu below `sm`; same handlers and URLs, each with `data-page-header-item="<key>"`). Put a condition that removes a whole slot on its `<template v-if … #slot>` so the header can drop an empty actions row and never shows an empty menu; set `primary-first` when the primary action came first, `align` from the old row's `items-*`. At `sm` and up the layout is the old row, pixel for pixel. `PageHeaderTest` pins the phone menu, its keyboard model, the desktop row and each migrated header at 375 px; `MobileLayoutTest` pins the whole pages listed in its member and admin datasets at 375 px.

## Settings rows use SettingsRow
A label-beside-control settings row is `SettingsRow` from `@/components/mm`: `<SettingsRow><template #label><Field …/></template><control/></SettingsRow>`. Never write an inline `style="grid-template-columns: 200px 1fr"` (or any fixed `grid-template-columns` style): a fixed or `1fr` track has an automatic minimum, so a wide control stretches the row past a phone screen. `SettingsRow` is `grid-cols-1` below `sm` (label above control) and `200px minmax(0,1fr)` with `gap-6` from `sm` up. `SettingsRowTest` pins both widths.

## Dialog and sheet close buttons stay on screen
`DialogContent` and `SheetContent` scroll their own content, so their close X must not scroll with it. Below `sm`:
- **`SheetContent`'s X (a right-side sheet)** is `max-sm:fixed`, because the sheet is full width there and has no transform after its slide-in — it stays pinned to the viewport while the sheet's own `overflow-y-auto` scrolls under it. It carries `data-sheet-close`.
- **`DialogContent`'s X never scrolls and is never `sticky`.** Chromium does not apply flex `order` to a `position: sticky` item's static-position/stick-range calculation — confirmed: a sticky item reordered via flex `order` (on itself **or** on a non-sticky wrapper ancestor) never actually sticks at any scroll offset, it just scrolls away like a `static` item, even though `getComputedStyle` reports `order` and `position: sticky` correctly. Reordering the X to be literally first in **DOM** order instead (no `order`) does make `sticky` work, but it also changes the dialog's default Tab order and (absent an explicit `openAutoFocus`) its default auto-focus target at every width, not just the phone one — too big a side effect to fold in silently.
  Instead, `DialogContent` itself stops scrolling below `sm` (`max-sm:overflow-hidden`, overriding its own base `overflow-y-auto`). Its real content is wrapped in `[data-slot="dialog-body"]` (`contents max-sm:flex max-sm:flex-col max-sm:min-h-0 max-sm:flex-1 max-sm:gap-4 max-sm:overflow-y-auto`) — `display: contents` at `sm`+ means it has **no box there at all** (same "a wrapper div that paints nothing" exemption as elsewhere; it never needs a parity-capture `ignored` entry), so desktop's DOM and layout are unchanged. Below `sm` it is the thing that actually scrolls. The X keeps its original place in the DOM (after the body wrapper, same as before this change) and stays plain `absolute top-4 right-4` at **every** width (plus `max-sm:rounded-full max-sm:bg-background` for the phone pill look): since `DialogContent` itself never moves, an `absolute` sibling positioned relative to it never moves either — "stays on screen while the body scrolls" without `sticky` or `order` at all.
- **Desktop** is unchanged either way: `DialogContent` keeps `grid`/`overflow-y-auto` and the X keeps `absolute top-4 right-4`.

A dialog or sheet consumer whose content needs the X's corner reserves it with `pr-10`/`pr-12`. `OverlayCloseTest` pins both the dialog and the sheet; it scrolls `[data-slot="dialog-body"]` (not `[role="dialog"]`) to prove the dialog case, and `MobileContainmentTest` does the same for its own "taller than the viewport" case.

## Phone tables become PhoneCardList cards
On the phone-job pages (Action Queue, SABnzbd queue and history, grab queue, watch history, interactive search releases) a table becomes stacked cards below `sm`:
- **The table:** keep it, and give its frame `max-sm:hidden`.
- **The cards:** render them with `PhoneCardList` from `@/components/mm` (`sm:hidden`, a `card` slot per item, an optional `empty` slot), placed **after** the table in the DOM. Desktop text clicks then keep finding the visible table first.
- **Hooks:** every hook inside a card gets its own `data-<x>-card-*` name (`data-action-card-select`, `data-sab-card-delete`), never the table row's. Pest's strict selectors count hidden elements, so a reused hook breaks every desktop test that uses it.
- **Select-all on phones:** shared components with a static hook (`BulkSelectAll`'s `data-bulk-select-all`) are not repeated in cards. Phone select-all is the compact bulk bar's ⋯ item "Select all on this page" (`[data-bulk-select-all-phone]`).
- **What a card holds:** the row's key facts and every row action, with long file or release names in `break-all` and titles in `break-words`.

Admin-only tables keep their columns and scroll inside their frame. `ActionQueuePhoneTest`, `DownloadsPhoneTest`, `InteractiveSearchPhoneTest` and `MonitoringPhoneTest` pin it.
