---
paths:
  - 'resources/js/**/*.vue'
  - 'resources/js/**'
---

# Js

## Single-file components
Write every SFC with `&lt;script setup lang="ts"&gt;` placed before `&lt;template&gt;`. No Options API, no scoped `&lt;style&gt;` blocks — style with Tailwind utilities in the template. Declare emits with the type-only generic `defineEmits&lt;{...}&gt;()`, never the runtime array form.

## URLs and forms come from Wayfinder
Build every internal URL from Wayfinder — `@/actions/...` for controller actions, `@/routes/...` for named routes. Never hardcode a path or call a `route()` helper. Submit with `&lt;Form v-bind="Controller.action.form()" v-slot="{ errors, processing }"&gt;`; reserve `useForm` for forms whose state is pre-populated and reactively edited, and use `router.&lt;verb&gt;(Controller.action.url(...))` for imperative one-shot actions.

## Tailwind styling with semantic tokens
Use only the semantic color tokens from `resources/css/app.css` (`bg-card`, `text-muted-foreground`, `text-fg-subtle`, `bg-bg-hover`, `text-success/warning/info`, `text-svc-*`) — never raw palette scales like `neutral-500`, and avoid `dark:` overrides since tokens are theme-aware. Merge conditional classes with `cn()`; accept a `class?: HTMLAttributes['class']` prop and merge it last. Reserve `cva` for `components/ui` primitives — `mm/` components use `computed()` variant maps.

## Toasts are server-driven
User feedback comes from the server via `Inertia::flash('toast', ...)`, rendered by the single global flash listener in `lib/flashToast.ts`. Call vue-sonner's `toast.*` directly only in `fetch`/stream paths that bypass an Inertia visit; ad-hoc JSON calls go through the shared `jsonRequest&lt;T&gt;()` helper (no axios).

## useHttp onError only covers 422s
Inertia's `useHttp` calls `onError` only for a 422 validation response. A 500 goes to `onHttpException`, a dropped connection to `onNetworkError`, and in both cases the returned promise rejects. Handle all three callbacks and `.catch()` the promise for every request whose failure must be visible. Otherwise state stays stuck (e.g. SubtitleItemDrawer left every operation disabled), nothing tells the user, and the rejection goes unhandled. Pest browser tests won't catch this: they record only window errors and console.* calls, not unhandled rejections or failed-resource messages.

## Byte sizes render through formatBytes()
Format every byte count with `formatBytes()` from `@/lib/format` (binary steps, whole numbers to MB, one decimal from GB, `"0 B"` for zero, `"—"` for a missing, negative or non-finite value). Never add a local size formatter; `tests/Browser/ByteFormatTest.php` pins the output.

## JSON requests come from @/lib/http
Ad-hoc JSON calls use `jsonRequest&lt;T&gt;()` and CSRF headers use `csrfToken()`, both from `@/lib/http`. Never import them from a composable (`useAiChat` used to host `jsonRequest`) and never read the `csrf-token` meta tag directly.

## Confirm with useConfirm(), never the browser dialog
Ask before a destructive or costly action with `const { confirm } = useConfirm(); if (!(await confirm({ title, description?, confirmLabel, destructive }))) return;` — one `ConfirmDialog` is mounted in the app layout. Pass `destructive: true` for deletes, removals, unlinks, declines and blocklists. ESLint bans the global `confirm` and `window.confirm`. Browser tests click `[data-confirm-accept]` / `[data-confirm-cancel]` instead of stubbing `window.confirm`.

## Hover prefetch: sidebar leaves and library cards only
`NavMain` leaves and the Sonarr/Radarr/Whisparr library grid cards use Inertia's `<Link prefetch>` (hover, 75 ms delay); `app.ts` keeps prefetched pages 10 s (`defaults.prefetch.cacheFor`), because Inertia does not drop them after a write. Other links do not prefetch. A prefetch that lands on the current URL is applied at once, remounting the page and dropping its local state; Inertia's `router.prefetch()` refuses the current URL, and `PrefetchTest` pins that, so re-check it after an Inertia upgrade. A `NavItem` sets `prefetch: false` when its page, in its initial (non-deferred) props, reads an upstream service that is uncached or whose failures are not cached (Downloads/SABnzbd, Subtitles/Bazarr), runs heavy un-deferred DB aggregation (AI Usage), or dispatches work/has a side effect on GET (Seasonal Anime's bootstrap sync job). A browser test that clicks inside a prefetching card (a bulk checkbox) must fake that title's detail endpoint, or the prefetch walks the factory host. `tests/Browser/PrefetchTest.php` pins it.

## Action Queue live results merge by status
`ActionRequestStatusChanged` carries only `ActionRequestBrowserPayload::safeResult()`. `Actions/Index.vue` folds it into a row with `mergeLiveResult()` (`@/components/actions`): merged over the existing result while the status and the reason are unchanged (an admin keeps the `message` and `indeterminate` the page props brought), replaced when either changes, cleared by a `null` result (a retry). Every live `failed` event an admin sees, same status or not, schedules the debounced `requests` reload to fetch the full result: Echo does not replay events missed while the socket was down, so a `failed → failed` event can stand for a whole retry and a new outcome. Read failure detail only through the `components/actions` helpers: an uncertain outcome is `indeterminate: true`, or `needs_reconciliation` without an explicit `indeterminate: false` (a never-started request), and only that case gates Retry behind `useConfirm()`. `tests/Browser/ActionQueueFailureTest.php` drives the live cases by emitting on `window.Pusher.instances[0].channel('private-members.actions')`, since tests broadcast through the null driver.

## Library card controls sit beside the card link
A library grid card is a `<Link>` (it keeps every `data-*-card` hook and the hover prefetch), wrapped in a `group relative` `div`. Interactive controls on the card (`BulkCheckbox`, Radarr's open-in-Radarr link) live in a sibling overlay `div.pointer-events-none.absolute.inset-x-0.top-0.aspect-[2/3]` sized like the poster, each with `pointer-events-auto`; never put a button, checkbox or second link inside the card's anchor. Hover styling keys off the wrapper's `group`. On Whisparr the wrapper owns `focusin`/`focusout` and the checkbox wrapper repeats the poster's `pointerenter`/`pointerleave`, so focusing or hovering the checkbox keeps the poster revealed. `LibraryBulkActionsTest` and `WhisparrLibraryTest` pin it.

## Focus after a dialog-confirmed bulk run
Call `focusAfterBulk()` (`@/lib/bulk`) after every successful bulk run, dialog or not. When focus is still inside a closing `[role="dialog"]`, it waits for the dialog to leave the DOM and for Reka's zero-delay focus restore, then moves focus to the enabled select-all (else the `h1`) only if it fell to `<body>`. Do not add per-dialog `@close-auto-focus` handlers for this. `SabnzbdBulkTest`, `GrabQueueBulkTest` and `ActionQueueBulkTest` pin it.
