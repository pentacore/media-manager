---
paths:
  - 'app/Http/**'
---

# Http

## Validate enum-backed fields with EnumUtils::validationRule()
For any field constrained to an enum, use `SomeEnum::validationRule()` (from the EnumUtils concern). Do not use `Rule::enum()`, `new Enum(...)`, or a hand-written `in:a,b,c` string.

## Redirect with to_route() or back(), never literal paths
Use `to_route('name')` to send the user elsewhere and `back()` to return to the submitting page; never `redirect()->route()`, `url('/path')`, or `action([])`. Generate URLs for props and notifications with `route('name')`.

## Iterate generator streams yourself under Octane FrankenPHP
With `$_SERVER['LARAVEL_OCTANE']` set, `response()->stream()` keeps a generator callback as-is and expects the server to iterate it; Octane's FrankenPHP client only calls `send()`, so the body is silently empty in production while `artisan serve` works. Any response built from a generator (including laravel/ai protocol streams) must be wrapped so the callback's returned Generator is iterated with echo + `ob_flush()` + `flush()` (see `ChatStreamProtocol::writeFrames()`). Cover it with a feature test that sets `LARAVEL_OCTANE=1`.
