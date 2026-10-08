---
paths:
  - 'resources/js/pages/**'
---

# Pages

## Page props and layouts
Declare page props with `defineProps&lt;{ ... }&gt;()` using an inline type literal (`withDefaults()` for defaults); import types from `@/typefinder` when the prop is backed by an API Resource or PHP enum. Never import or wrap a layout inside a page — layouts resolve by page-name prefix in `resources/js/inertia.ts`; pass breadcrumbs via `defineOptions({ layout: { breadcrumbs: [...] } })` with Wayfinder hrefs. Render `&lt;Head title="..." /&gt;` as the first template node. Read shared props through `usePage()` typed once in `types/global.d.ts` — never re-cast them locally.

## Oversized pages split into feature components
A page is a thin composer: props, `defineOptions`, the composables it needs and one child per section. When a page grows past about 500 lines, move each section into `components/<feature>/` (PascalCase `.vue`, exported from the folder's `index.ts`; feature-local types in `types.ts`, pure helpers in a plain `.ts` beside them) and state shared across sections into a `useX` composable. Put a section's `v-if` on the component tag, never inside the component, and keep state that must survive the section unmounting in the page, passed down with `v-model` (the Bazarr mapping ids and Whisparr version on the connection forms switch in and out with the service type). A component has a single root, so sibling fields that differ per page stay inline rather than gaining a wrapper element. A split is a pure refactor: every `data-*` hook, string and request stays on the same element. Pin the page with browser tests first and compare before/after screenshots. Batch 3c did this for AI Usage, AI Prices and Connection Edit.
