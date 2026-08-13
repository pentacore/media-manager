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
