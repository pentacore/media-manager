---
paths:
  - 'app/Enums/*.php'
---

# Enums

## Enum shape
Declare enums string-backed with PascalCase case names, lowercase snake_case values, and no `Enum` suffix. Add `use EnumUtils` and a `label(): string` built from `match ($this)`, and keep domain predicates on the enum rather than in callers.

## Ability values stay kebab-case
`App\Enums\Ability` is the one ability vocabulary: `Abilities`' constants alias it and Typefinder emits it as the TS `Ability` union (`types/auth.ts` re-exports it; never hand-write the union). Its values are Gate names and `auth.can` keys, so they stay kebab-case — the one exception to the snake_case enum-value rule above. Add an ability as an enum case plus a `MINIMUM_ROLES` entry; `AbilitiesTest` pins the parity.
