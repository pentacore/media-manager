---
paths:
  - 'resources/js/components/**'
---

# Components

## Component placement and imports
Put shadcn-vue primitives in `components/ui/&lt;kebab-folder&gt;/`, house design-system primitives in `components/mm/`, feature components in `components/&lt;feature&gt;/`, and app-shell components at the root. Name files PascalCase and import via the folder's `index.ts` barrel, not deep file paths. Icons are `@lucide/vue` named imports sized with Tailwind classes.
