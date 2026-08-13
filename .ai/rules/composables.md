---
paths:
  - 'resources/js/composables/**'
---

# Composables

## Composables
Name the file and its named export `useX`; export a `function`, never a default or arrow const. Declare and export an explicit `UseXReturn` type for the returned object. Composables owning an Echo/Reverb channel expose `subscribe`/`unsubscribe` and self-release from `onUnmounted`.
