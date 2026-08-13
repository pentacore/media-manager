---
paths:
  - 'app/Concerns/*.php'
---

# Concerns

## Concerns hold cross-layer traits only
Use `app/Concerns` only for traits shared across layers — `*ValidationRules` providers exposing an `*Rules(): array` method, and `EnumUtils`. Model behaviour traits do not live here (or anywhere — there are none).
