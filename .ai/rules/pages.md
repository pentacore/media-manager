---
paths:
  - 'resources/js/pages/**'
---

# Pages

## Page props and layouts
Declare page props with `defineProps&lt;{ ... }&gt;()` using an inline type literal (`withDefaults()` for defaults); import types from `@/typefinder` when the prop is backed by an API Resource or PHP enum. Never import or wrap a layout inside a page — layouts resolve by page-name prefix in `resources/js/inertia.ts`; pass breadcrumbs via `defineOptions({ layout: { breadcrumbs: [...] } })` with Wayfinder hrefs. Render `&lt;Head title="..." /&gt;` as the first template node. Read shared props through `usePage()` typed once in `types/global.d.ts` — never re-cast them locally.
