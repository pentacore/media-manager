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
A page's hero header (title block on the left, actions on the right) is `PageHeader` from `@/components/mm`, never a new hand-rolled `flex … justify-between` row. Hand-rolled headers that already wrap at 375 px are deliberate exceptions until a later batch adopts them: `Anime/Season.vue`, `Library/Wanted.vue`, `Admin/Benchmarks/Runs/Show.vue`, `Admin/MediaReplacement/Attempts/Show.vue`, `Sonarr/Series/Create.vue`, `components/ai-prices/PricesHeader.vue` and `components/ai-usage/UsageHeader.vue`. The series and movie detail toolbars are on `PageHeader` too (the Back button in `#title`, Monitor/Search as `#primaryAction` with `primary-first`, the rest in `#secondaryActions` and `#menu`); the Whisparr show page's toolbar is still hand-rolled (3h-3). Pass the original elements into its slots: `eyebrow` (the `mb-1.5` breadcrumb line), `title` (the `h1`), `description`, `filters` (controls that stay visible on a phone), `secondaryActions` (inline buttons, desktop only), `primaryAction` (the one action a phone keeps) and `menu` (`DropdownMenuItem`s mirroring `secondaryActions` for the ⋯ menu below `sm`; same handlers and URLs, each with `data-page-header-item="<key>"`). Put a condition that removes a whole slot on its `<template v-if … #slot>` so the header can drop an empty actions row and never shows an empty menu; set `primary-first` when the primary action came first, `align` from the old row's `items-*`. At `sm` and up the layout is the old row, pixel for pixel. `PageHeaderTest` pins the phone menu, its keyboard model, the desktop row and each migrated header at 375 px; `MobileLayoutTest` pins the whole pages listed in its member and admin datasets at 375 px; `DetailToolbarPhoneTest` pins the series and movie toolbars. A dialog that a `#menu` item opens takes `@close-auto-focus="returnFocusToPageHeaderMenu($event, '<its trigger selector>')"` (`@/lib/pageHeader`): below `sm` its `DialogTrigger` sits in the hidden `#secondaryActions`, so Reka's focus restore would drop focus to `<body>`; the helper sends it back to `[data-page-header-more]` and leaves desktop's restore alone.

## Settings rows use SettingsRow
A label-beside-control settings row is `SettingsRow` from `@/components/mm`: `<SettingsRow><template #label><Field …/></template><control/></SettingsRow>`. Never write an inline `style="grid-template-columns: 200px 1fr"` (or any fixed `grid-template-columns` style): a fixed or `1fr` track has an automatic minimum, so a wide control stretches the row past a phone screen. `SettingsRow` is `grid-cols-1` below `sm` (label above control) and `200px minmax(0,1fr)` with `gap-6` from `sm` up. `SettingsRowTest` pins both widths.

## Dialog and sheet close buttons stay on screen
`DialogContent` and `SheetContent` scroll their own content, so their close X must not scroll with it. Below `sm`:
- **`SheetContent`'s X (a right-side sheet)** is `max-sm:fixed`, because the sheet is full width there and has no transform after its slide-in — it stays pinned to the viewport while the sheet's own `overflow-y-auto` scrolls under it. It carries `data-sheet-close`.
- **`DialogContent` itself stops scrolling below `sm`** (`max-sm:overflow-hidden`, overriding its own base `overflow-y-auto`). Its real content is wrapped in `[data-slot="dialog-body"]` — `display: contents` at `sm`+, so it has **no box there at all** (same "a wrapper div that paints nothing" exemption as elsewhere; it never needs a parity-capture `ignored` entry) and desktop's DOM/layout are unchanged. Below `sm` it is a `flex flex-col` column and the thing that actually scrolls (`max-sm:overflow-y-auto`, `max-sm:overflow-x-hidden` so a child's own negative margin can never cause a horizontal scroll wobble; `max-sm:-mx-2 max-sm:px-2` is the matching gutter, sized to the `-mr-2`/`pr-2` pattern dialog bodies already use for their own inner scrollers, e.g. `InvocationDetailDialog`). The X keeps its original place in the DOM (after the body wrapper) and stays plain `absolute top-4 right-4` at **every** width (plus `max-sm:z-10 max-sm:rounded-full max-sm:bg-background` for the phone pill look): since `DialogContent` itself never moves, an `absolute` sibling positioned relative to it never moves either — "stays on screen while the body scrolls" without `sticky`.
- **Desktop** is unchanged either way: `DialogContent` keeps `grid`/`overflow-y-auto` and the X keeps `absolute top-4 right-4`.

A consumer that needs this to work must know:
- **the gap is inherited, not hard-coded.** `[data-slot="dialog-body"]`'s `max-sm:gap-[inherit]` takes whatever `gap-*` the consumer set on `DialogContent` itself (the default is `gap-4`; `CommandPalette` overrides it to `gap-0`). Don't reintroduce a fixed `max-sm:gap-*` on the body — it would fight a consumer's own gap.
- **a direct child that is itself a scroll container (`overflow-y-auto`, `max-h-*`) shrinks below `sm`,** because flex items default to `min-height: auto` only when `overflow: visible`; one with its own `overflow` has an automatic minimum of 0 and absorbs the body's available height instead of forcing the body to scroll past it. This is neutral-to-better for a component built as an inner scroller (`InteractiveSearchDialog`, `ReplaceFileDialog`, the command palette's results list), but a future direct child using `overflow-hidden`, `truncate` or `line-clamp` would get clipped instead of shown — give it `shrink-0` if it must keep its full height.
- **a phone test that needs the scroller targets `[data-slot="dialog-body"]`, never `[role="dialog"]`** — the dialog's own box no longer scrolls. `OverlayCloseTest` and `MobileContainmentTest` do this.
- a dialog whose content needs the X's corner reserves it with `pr-10`/`pr-12`.

A short-viewport consumer (the command palette in landscape) additionally needs its own `max-height`-based media-query variant (`flex`/`flex-col`, matching its existing `[@media(max-height:500px)]:top-4`) on `DialogContent`, so its direct children (passed straight through the `contents` body wrapper) become flex items that can shrink and scroll, instead of grid auto-rows that overflow a clamped `max-h-*` silently. `OverlayCloseTest` pins the dialog, the sheet, the palette's phone gap and its landscape scrolling.

## Phone tables become PhoneCardList cards
On the phone-job pages (Action Queue, SABnzbd queue and history, grab queue, watch history, interactive search releases) a table becomes stacked cards below `sm`:
- **The table:** keep it, and give its frame `max-sm:hidden`.
- **The cards:** render them with `PhoneCardList` from `@/components/mm` (`sm:hidden`, a `card` slot per item, an optional `empty` slot), placed **after** the table in the DOM. Desktop text clicks then keep finding the visible table first.
- **Hooks:** every hook inside a card gets its own `data-<x>-card-*` name (`data-action-card-select`, `data-sab-card-delete`), never the table row's. Pest's strict selectors count hidden elements, so a reused hook breaks every desktop test that uses it.
- **Select-all on phones:** shared components with a static hook (`BulkSelectAll`'s `data-bulk-select-all`) are not repeated in cards. Phone select-all is the compact bulk bar's ⋯ item "Select all on this page" (`[data-bulk-select-all-phone]`).
- **What a card holds:** the row's key facts and every row action, with long file or release names in `break-all` and titles in `break-words`.
- **One empty message:** a card list's `empty` slot must not repeat text that the table's own empty row already shows, because a strict `assertSeeIn` counts the hidden copy as a second match. The SABnzbd queue and history keep the table's empty row as the only message. Their table frame is `max-sm:hidden` only while it has rows, their `thead` is `max-sm:hidden`, and the `PhoneCardList` renders only when there are rows (`v-if`).

Admin-only tables keep their columns and scroll inside their frame. `ActionQueuePhoneTest`, `DownloadsPhoneTest`, `InteractiveSearchPhoneTest` and `MonitoringPhoneTest` pin it.

## The Action Queue detail has a pane and a sheet layout
`ActionRequestDetail` is the one implementation of an action's detail, review buttons and failure/second-opinion cards:
- **`layout="pane"`** (default) is the desktop panel beside the table, `max-sm:hidden`.
- **`layout="sheet"`** renders the same markup inside `ActionRequestSheet`, the phone's full-screen `Sheet`. The card stops clipping (sticky needs a non-clipping ancestor) and the Approve/Decline and Retry rows become `sticky bottom-0` bars.

Never fork the detail for a new presentation; add a layout case.

The sheet opens from a card (`openSheet`) or, on a phone, from a `?request=<id>` deep link in `onMounted` (`openDeepLinkedSheet()`, called from the page's single `onMounted` after `subscribeLive()`: `ActionQueueLiveListTest` re-runs the page's only mounted hook, so never add a second one). On close it focuses the card and brings it back into view with `scrollIntoView({ block: 'nearest' })`, not a saved `scrollY`, because a live row can be added above it meanwhile; when the card has left the list, focus goes to the page `h1` (`focusPageHeading()` from `@/lib/bulk`). Both run from `@close-auto-focus`, default prevented. Closing also drops `?request=` from the URL with a client-side `router.replace` (no new history entry), so a reload or back/forward does not reopen a deep link. The page's `visibleRequests` watcher closes the sheet before it moves the selection, and the sheet renders a held row (`shownRow`, frozen once `open` turns false) with the detail `inert` while it slides out, so a row that leaves the list (approved under the Pending tab) never turns the sheet into another action, not even during the exit animation. Live status changes need nothing extra, because the sheet renders the same `selected` row `useActionQueueLive` orders by `status_version`. `ActionQueuePhoneTest` pins it.

## Bulk bars are one 56 px row on a phone
`BulkActionBar` from `@/components/bulk` takes every action in its default slot (the desktop row).
- **A phone-job surface** also passes `#primary` (the one action a phone keeps, `h-10`) and `#menu` (`DropdownMenuItem`s for the rest). Below `sm` the bar is then one `h-14` row: the count, the primary action, and ⋯ (`[data-bulk-more]`) with the menu items, the over-limit note and "Clear selection".
- **Hooks:** give each phone action its own hook (`data-bulk-approve-phone`, `data-bulk-menu-item="<key>"`), never the inline button's: strict selectors count hidden duplicates.
- **Phone select-all:** each adopter's `#menu` starts with "Select all on this page" (`[data-bulk-select-all-phone]`, calling the page's `setAll…(pageIds, true)`), because card lists never repeat `BulkSelectAll`.
- **A bar without `#primary`** keeps the wrapping row at every width. The library bars do this, because their quality-profile `Select` cannot live in a menu.

`BulkBarPhoneTest` pins the height, the menu, select-all, the clear and the frozen state during a run.
