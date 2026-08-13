---
paths:
  - 'app/Settings/*.php'
---

# Settings

## Application settings
Model settings as a typed class wrapping the injected `AppSettings` store, with `*_KEY` constants and paired typed getter/setter methods. Always fall back to a `config('mediamanager.*')` default when nothing is persisted, and treat `null` on the setter as "clear back to config".
