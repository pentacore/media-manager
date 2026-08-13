---
paths:
  - 'app/Http/**'
---

# Http

## Validate enum-backed fields with EnumUtils::validationRule()
For any field constrained to an enum, use `SomeEnum::validationRule()` (from the EnumUtils concern). Do not use `Rule::enum()`, `new Enum(...)`, or a hand-written `in:a,b,c` string.

## Redirect with to_route() or back(), never literal paths
Use `to_route('name')` to send the user elsewhere and `back()` to return to the submitting page; never `redirect()->route()`, `url('/path')`, or `action([])`. Generate URLs for props and notifications with `route('name')`.
