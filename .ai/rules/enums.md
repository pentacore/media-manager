---
paths:
  - 'app/Enums/*.php'
---

# Enums

## Enum shape
Declare enums string-backed with PascalCase case names, lowercase snake_case values, and no `Enum` suffix. Add `use EnumUtils` and a `label(): string` built from `match ($this)`, and keep domain predicates on the enum rather than in callers.
